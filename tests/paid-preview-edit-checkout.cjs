// Native PHP URL/checkout consumers plus the actual Add Listing JS success callback.
// No HTTP, WordPress bootstrap, database writes, orders or payment submissions.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const { execFileSync } = require("node:child_process");
const parser = require("@babel/parser");
const traverse = require("@babel/traverse").default;

const root =
  process.env.DIRECTORIST_TEST_SOURCE_ROOT || path.resolve(__dirname, "..");
const source = fs.readFileSync(
  path.join(
    process.env.DIRECTORIST_TEST_JS_SOURCE_ROOT || root,
    "assets/src/js/global/add-listing.js",
  ),
  "utf8",
);
const ast = parser.parse(source, { sourceType: "module" });
const snippets = {};
traverse(ast, {
  FunctionDeclaration({ node }) {
    if (["joinQueryString", "normalizeRedirectUrl"].includes(node.id?.name))
      snippets[node.id.name] = source.slice(node.start, node.end);
  },
  VariableDeclarator({ node }) {
    if (node.id.name === "qs")
      snippets.query = source.slice(node.init.start, node.init.end);
  },
  ObjectProperty({ node }) {
    if (
      node.key.name === "success" &&
      source
        .slice(node.value.start, node.value.end)
        .includes("encoded_redirect_url")
    ) {
      snippets.success = source.slice(node.value.start, node.value.end);
    }
  },
});
for (const key of [
  "query",
  "success",
  "joinQueryString",
  "normalizeRedirectUrl",
])
  assert.ok(snippets[key], `Missing actual source producer: ${key}`);
const php = (data) =>
  JSON.parse(
    execFileSync(
      "php",
      [path.join(__dirname, "php/paid-preview-edit-checkout.php")],
      {
        input: JSON.stringify(data),
        encoding: "utf8",
        env: process.env,
      },
    ),
  );
const query = (url) =>
  Object.fromEntries(
    new URL(url, "https://checkout.example.test").searchParams,
  );
const previewAfterSave = (
  editUrl,
  redirectUrl = "",
  edited = true,
  needPayment = false,
) => {
  const context = {
    window: { location: { search: new URL(editUrl).search, href: "" } },
    response: {
      id: 42,
      preview_mode: true,
      edited_listing: edited,
      need_payment: needPayment,
      preview_url: "https://checkout.example.test/listing/42/",
      redirect_url: redirectUrl,
      success_msg: "Saved",
    },
    $notification: {
      show() {
        return this;
      },
      html() {
        return this;
      },
    },
    localized_data: { i18n_text: {} },
  };
  vm.runInNewContext(
    `${snippets.joinQueryString}\n${snippets.normalizeRedirectUrl}\nconst qs=${snippets.query};(${snippets.success})(response);`,
    context,
  );
  return context.window.location.href;
};

const original =
  "https://checkout.example.test/checkout/?plan_id=9&listing_id=42&is_featured=1&checkout_type=plan";
const firstEdit = php({ request: { payment: "1", redirect: original } });
const firstPreview = previewAfterSave(firstEdit.url);
const firstSubmit = php({ action: "submit", request: query(firstPreview) });
const firstCheckout = php({
  action: "checkout",
  request: query(firstSubmit.url),
});
if (process.argv.includes("--expect-broken")) {
  assert.equal(
    firstCheckout.invalid_type,
    true,
    "The baseline must reproduce Invalid checkout type",
  );
  console.log(
    JSON.stringify(
      {
        baseline: "broken",
        outer_edit_keys: Object.keys(firstEdit.query),
        received_redirect: query(firstPreview).redirect,
        checkout: firstCheckout,
      },
      null,
      2,
    ),
  );
  process.exit(0);
}

let checks = 0;
const check = (ok, message) => {
  assert.ok(ok, message);
  checks++;
};
const results = [];
const cases = [
  original,
  original +
    "&memo=" +
    encodeURIComponent('A+B & "quoted" / \u09ac\u09be\u0982\u09b2\u09be') +
    "&return=" +
    encodeURIComponent("/account/?a=1&b=2") +
    "&signature=abc%2B%2F%3D%3D&literal=%2526" +
    "#billing",
  "https://checkout.example.test/?page_id=3&plan_id=9&listing_id=42&is_featured=0&checkout_type=plan",
  "/checkout/?plan_id=9&listing_id=42&is_featured=1&checkout_type=plan#billing",
];
for (const pretty of [true, false]) {
  for (const redirect of cases) {
    const request = { payment: "1", redirect };
    for (let round = 0; round < 3; round++) {
      const edit = php({ pretty, request });
      check(
        edit.query.redirect === redirect,
        "Edit preserves the complete nested URL without double encoding",
      );
      check(
        !("_token" in edit.query),
        "Edit keeps its native unsigned URL contract",
      );
      check(
        !["plan_id", "listing_id", "is_featured", "checkout_type"].some(
          (key) => key in edit.query,
        ),
        "Checkout keys cannot escape into the Edit query",
      );
      const preview = previewAfterSave(edit.url);
      const next = query(preview);
      check(
        next.redirect === redirect,
        "Actual Preview Changes callback preserves the nested URL",
      );
      check(
        next.payment === "1" && next.edited === "1" && next.post_id === "42",
        "Native paid/edited/listing state survives",
      );
      const submit = php({ pretty, action: "submit", request: next });
      check(
        submit.valid_nonce &&
          submit.query.atbdp_listing_id === "42" &&
          submit.query.reviewed === "yes",
        "Native Continue remains correctly signed and scoped",
      );
      check(
        submit.tampered_nonce_rejected,
        "Native WordPress rejects a tampered continuation token",
      );
      const checkout = php({ action: "checkout", request: query(submit.url) });
      check(
        checkout.valid && checkout.pay_now && !checkout.invalid_type,
        "Actual native checkout renders the paid form",
      );
      check(
        checkout.plan_id === "9" &&
          checkout.listing_id === "42" &&
          checkout.checkout_type === "plan",
        "Actual checkout request receives the original plan/listing/type",
      );
      check(
        new URL(submit.url, "https://checkout.example.test").hash ===
          new URL(redirect, "https://checkout.example.test").hash,
        "Nested checkout fragment survives",
      );
      for (const key of [
        "memo",
        "return",
        "is_featured",
        "signature",
        "literal",
      ]) {
        check(
          submit.query[key] === query(redirect)[key],
          `Encoded checkout value ${key} is unchanged`,
        );
      }
      Object.assign(request, next);
    }
    results.push({
      pretty,
      redirect_kind: redirect.startsWith("/") ? "relative" : "absolute",
      checkout: "valid",
      edit_round_trips: 3,
    });
  }
}
for (const redirect of cases.slice(0, 3)) {
  for (const supplied of [redirect, encodeURIComponent(redirect)]) {
    const initialPreview = previewAfterSave(
      "https://checkout.example.test/add-listing/",
      supplied,
      false,
      true,
    );
    check(
      query(initialPreview).redirect === redirect,
      "Fresh paid preview preserves already-encoded query values and accepts the existing encoded response contract",
    );
    const submit = php({ action: "submit", request: query(initialPreview) });
    check(
      php({ action: "checkout", request: query(submit.url) }).valid,
      "Fresh paid checkout still renders",
    );
  }
}
for (const redirect of [
  "https://evil.example/checkout/?checkout_type=plan",
  "//evil.example/checkout/",
  "https://checkout.example.test@evil.example/checkout/",
  "javascript:alert(1)",
  [],
  null,
]) {
  const edit = php({ request: { payment: "1", redirect } });
  check(
    edit.url === edit.base,
    "Invalid/external redirect never enters the Edit URL",
  );
}
const free = php({ request: { redirect: original } });
check(free.url === free.base, "Ordinary unpaid Edit URL stays unchanged");
const noDestination = php({ request: { payment: "1" } });
check(
  noDestination.url === noDestination.base,
  "Absent continuation does not invent an Edit redirect",
);
check(
  php({ request: { payment: [], redirect: original } }).url === free.base,
  "Malformed payment state cannot force the paid Edit branch",
);
const freeDestination = "https://checkout.example.test/listing/42/?notice=1";
for (const edited of [false, true]) {
  const preview = previewAfterSave(free.url, freeDestination, edited, false);
  const submit = php({ action: "submit", request: query(preview) });
  check(
    submit.valid_nonce &&
      submit.query.p === "42" &&
      submit.query.post_id === "42",
    "Unpaid native continuation remains signed and listing-scoped",
  );
  check(
    submit.query.edited === (edited ? "yes" : "no") &&
      submit.query.notice === "1",
    "Free new/edit state and existing destination query remain unchanged",
  );
}
console.log(
  JSON.stringify(
    {
      suite:
        "native paid Preview Edit Preview Continue checkout URL round trip",
      checks,
      results,
      scope:
        "Actual WordPress URL/redirect/nonce APIs, Core getters/permalinks, actual Add Listing callback and native Core checkout/validator/template; isolated storage/auth/plan configuration, no HTTP or DB writes",
    },
    null,
    2,
  ),
);

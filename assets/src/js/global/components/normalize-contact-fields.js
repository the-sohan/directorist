// Match the server's literal boundary policy; never decode URLs or email addresses.
const boundaryWhitespace =
  /^[\u0009-\u000d\u0020\u00a0\u1680\u2000-\u200b\u2028\u2029\u202f\u205f\u3000\ufeff]+|[\u0009-\u000d\u0020\u00a0\u1680\u2000-\u200b\u2028\u2029\u202f\u205f\u3000\ufeff]+$/g;
const contactSelector = [
  'input[type="url"]',
  'input[type="email"]',
  ".directorist-form-website-field input",
  'input[name="website"]',
  'input[name="user[website]"]',
].join(",");

function normalizeContactInput(input) {
  if (input instanceof HTMLInputElement && input.matches(contactSelector)) {
    input.value = input.value.replace(boundaryWhitespace, "");
  }
}

document.addEventListener(
  "blur",
  (event) => normalizeContactInput(event.target),
  true,
);
document.addEventListener("paste", (event) => {
  const input = event.target;
  setTimeout(() => normalizeContactInput(input), 0);
});
document.addEventListener(
  "submit",
  (event) => {
    event.target
      .querySelectorAll(contactSelector)
      .forEach(normalizeContactInput);
  },
  true,
);

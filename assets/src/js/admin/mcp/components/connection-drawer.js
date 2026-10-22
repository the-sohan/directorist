/* Directorist MCP: components/connection-drawer.js. Directly shipped source; no build required. */
(function (mcp) {
  "use strict";
  mcp.createConnectionDrawer = function (config) {
    return {
      name: "McpConnectionDrawer",
      components: { ClientConfiguration: mcp.createClientConfiguration() },
      props: {
        status: Object,
        enabled: Boolean,
        refreshStatus: Function,
        error: String,
      },
      data() {
        return {
          opened: true,
          clients: [],
          client: "claude-code",
          templatesLoading: false,
          username: "",
          password: "",
          showPassword: false,
          message: "",
          loading: false,
          templatesError: "",
          headingId: "directorist-mcp-" + this._uid,
        };
      },
      computed: {
        ready() {
          return Boolean(this.status && this.status.ready);
        },
        selectedClient() {
          return this.clients.find((item) => item.id === this.client) || null;
        },
      },
      created() {
        this._alive = true;
        this._api = mcp.createApiClient(config, () => this.close(false));
        this._modal = mcp.createModalAccessibility();
        this.username = this.status ? this.status.current_user_login || "" : "";
      },
      async mounted() {
        this._modal.open(this.$refs.overlay, this.$refs.close);
        await this.refresh();
        if (this._alive && this.opened && this.ready)
          await this.loadTemplates();
      },
      beforeDestroy() {
        this._alive = false;
        this.opened = false;
        this.password = "";
        this.username = "";
        this.clients = [];
        this._api.abort();
        this._modal.close();
      },
      watch: {
        ready(value) {
          if (!value) this.clients = [];
        },
      },
      methods: {
        request(url, options) {
          return this._api.request(url, options);
        },
        async refresh() {
          this.loading = true;
          try {
            await this.refreshStatus();
          } finally {
            if (this._alive) this.loading = false;
          }
        },
        close(restoreFocus = true) {
          this.password = "";
          this.username = "";
          this.clients = [];
          this.opened = false;
          this.$emit("close", restoreFocus);
        },
        async loadTemplates() {
          if (!this.opened || !this.ready || !this.enabled) return;
          this.templatesLoading = true;
          this.templatesError = "";
          try {
            const response = await this.request(config.templatesUrl);
            if (this._alive && this.opened && this.ready && this.enabled)
              this.clients = response.clients || [];
          } catch (error) {
            if (this._alive && error.name !== "AbortError")
              this.templatesError = error.message;
          } finally {
            if (this._alive) this.templatesLoading = false;
          }
        },
        snippet() {
          return mcp.buildSnippet({
            opened: this.opened,
            ready: this.ready,
            enabled: this.enabled,
            selectedClient: this.selectedClient,
            username: this.username,
            password: this.password,
          });
        },
        async copy(text, label) {
          this.message = "";
          try {
            await mcp.copyText(text, this.$refs.dialog);
            if (this.opened) this.message = label + " copied to clipboard.";
          } catch (_) {
            if (this.opened)
              this.message =
                "Could not copy. Select the text and copy it manually.";
          }
        },
        keydown(event) {
          this._modal.keydown(event, this.$refs.dialog, () => this.close());
        },
      },
      template: `            <div ref="overlay" class="directorist-mcp-overlay" @click.self="close()" @keydown="keydown">
                <section ref="dialog" class="directorist-mcp-drawer" role="dialog" aria-modal="true" :aria-labelledby="headingId">
                    <header><h2 :id="headingId">MCP Connection</h2><button ref="close" type="button" class="directorist-mcp-close" aria-label="Close MCP Connection" @click="close()">×</button></header>
                    <div class="directorist-mcp-drawer-body">
                        <p v-if="loading" role="status">Checking connection setup…</p>
                        <p v-if="error" role="alert" class="directorist-mcp-error">{{ error }} <button type="button" @click="refresh">Retry</button></p>
                        <div v-if="!ready" class="directorist-mcp-note"><h3>Setup required</h3><p>Install and activate a compatible WordPress MCP Adapter, then refresh. Configuration snippets are unavailable until setup is ready.</p><button type="button" class="directorist-mcp-button" @click="refresh">Refresh setup</button></div>
                        <template v-else-if="status">
                            <p><strong>{{ status.tools_count }}</strong> Directorist tools available to authorized accounts</p><p class="directorist-mcp-note">{{ status.groups.join(' · ') }}. Each action checks your WordPress permissions.</p>
                            <label class="directorist-mcp-label">Endpoint URL</label><div class="directorist-mcp-copy-row"><input aria-label="MCP endpoint URL" :value="status.endpoint_url" readonly><button type="button" class="directorist-mcp-button" @click="copy(status.endpoint_url, 'Endpoint URL')">Copy</button></div>
                            <div v-if="!status.https || !status.application_passwords_available" class="directorist-mcp-note"><p v-if="!status.https">Use a trusted HTTPS connection before supplying credentials.</p><p v-if="!status.application_passwords_available">Application passwords are unavailable for this user. Resolve the WordPress site policy before connecting.</p></div>
                            <h3>Connect a client</h3><p>Your application password stays in this open panel and is used only to prepare the snippet you copy. <a :href="status.profile_url" target="_blank" rel="noopener noreferrer">Create an application password</a> for your own WordPress user.</p>
                            <form class="directorist-mcp-credentials" autocomplete="off" @submit.prevent><label>WordPress username<input v-model="username" autocomplete="off" maxlength="256"></label><label>Application password<input v-model="password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" maxlength="512"></label><button type="button" class="directorist-mcp-link" @click="showPassword = !showPassword">{{ showPassword ? 'Hide password' : 'Show password' }}</button></form>
                            <client-configuration :clients="clients" :client="client" :heading-id="headingId" :templates-loading="templatesLoading" :templates-error="templatesError" :credentials-ready="!!username.trim() && !!password.trim()" :snippet="snippet()" @load-templates="loadTemplates" @select-client="client = $event" @copy-snippet="copy(snippet(), 'Connection snippet')" />
                            <details><summary>Verify the connection</summary><p>Initialize your compatible HTTP client, discover tools, then call directorist-get-context. Check your identity and permissions before making a change. OAuth-only clients are not supported.</p></details>
                        </template>
                        <p v-if="message" role="status" aria-live="polite" class="directorist-mcp-feedback">{{ message }}</p>
                    </div>
                </section>
            </div>`,
    };
  };
})((window.DirectoristMcp = window.DirectoristMcp || {}));

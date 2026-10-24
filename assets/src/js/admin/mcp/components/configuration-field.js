/* Directorist MCP: components/configuration-field.js. Directly shipped source; no build required. */
(function (mcp) {
  "use strict";
  mcp.createConfigurationField = function (config) {
    return {
      name: "McpConfigurationField",
      components: { ConnectionDrawer: mcp.createConnectionDrawer(config) },
      props: {
        value: { default: false },
        label: String,
        description: String,
        fieldKey: String,
      },
      data() {
        return {
          enabled: this.value === true || this.value === "1",
          status: null,
          loading: false,
          saving: false,
          installing: false,
          installPhase: "checking",
          opened: false,
          message: "",
          error: "",
        };
      },
      computed: {
        ready() {
          return Boolean(this.status && this.status.ready);
        },
        adapterSetup() {
          return this.status?.adapter_setup;
        },
        adapterLabel() {
          return this.installing
            ? config.adapterSetup.labels[this.installPhase]
            : this.adapterSetup?.label;
        },
        badge() {
          return this.loading && !this.status
            ? "Checking setup…"
            : !this.ready
              ? "Setup required"
              : this.enabled
                ? "Active"
                : "Inactive";
        },
      },
      created() {
        this._alive = true;
        this._api = mcp.createApiClient(config, () => this.close(false));
        this._installer = mcp.createAdapterInstaller(config, (url, options) =>
          this.request(url, options),
        );
        this.refresh();
        this._refocus = () => {
          if (this.opened && !this.installing) this.refresh();
        };
        this._navigation = () => this.close(false);
        window.addEventListener("focus", this._refocus);
        window.addEventListener("hashchange", this._navigation);
        window.addEventListener("pagehide", this._navigation);
      },
      beforeDestroy() {
        this._alive = false;
        this.close(false);
        this._api.abort();
        window.removeEventListener("focus", this._refocus);
        window.removeEventListener("hashchange", this._navigation);
        window.removeEventListener("pagehide", this._navigation);
      },
      methods: {
        request(url, options) {
          return this._api.request(url, options);
        },
        persist(value) {
          this.enabled = Boolean(value);
          this.$store.commit("setPersistedAsyncField", {
            field_key: this.fieldKey || "mcp_enabled",
            value: this.enabled,
          });
          this.$store.commit("updateFieldData", {
            field_key: this.fieldKey || "mcp_enabled",
            option_key: "forceUpdate",
            value: false,
          });
        },
        async refresh() {
          this.loading = true;
          this.error = "";
          try {
            const status = await this.request(config.statusUrl);
            if (!this._alive) return;
            this.status = status;
            this.persist(status.enabled);
            if (!status.enabled && this.opened) this.close();
            return status;
          } catch (error) {
            if (this._alive && error.name !== "AbortError")
              this.error = error.message;
          } finally {
            if (this._alive) this.loading = false;
          }
        },
        async toggle(next) {
          if (this.saving || this.loading || this.installing) return;
          this.saving = true;
          this.error = "";
          this.message = "";
          try {
            const body = new FormData();
            body.append("action", "save_settings_data");
            body.append("directorist_nonce", config.ajaxNonce);
            body.append("field_list", JSON.stringify(["mcp_enabled"]));
            body.append("mcp_enabled", JSON.stringify(Boolean(next)));
            const response = await this.request(config.ajaxUrl, {
              method: "POST",
              body,
            });
            if (!this._alive) return;
            if (!response.status || !response.status.success)
              throw new Error(
                response.status?.status_log?.message ||
                  "The setting could not be saved.",
              );
            const status = await this.refresh();
            if (!this._alive || !status) return;
            if (this.enabled !== Boolean(next))
              throw new Error(
                "The saved MCP state did not match the requested change. Please retry.",
              );
            this.message = this.enabled
              ? "MCP enabled. Configure a client to connect."
              : "MCP disabled.";
          } catch (error) {
            if (this._alive && error.name !== "AbortError")
              this.error = error.message;
          } finally {
            if (this._alive) this.saving = false;
          }
        },
        changeEnabled(event) {
          const next = event.target.checked;
          // Native checkboxes change before the server confirms persistence.
          event.target.checked = this.enabled;
          return this.toggle(next);
        },
        async setupAdapter() {
          if (this.installing || this.loading || this.saving) return;
          this.installing = true;
          this.installPhase = "checking";
          this.error = "";
          this.message = "";
          try {
            // Reconcile a stale page before choosing install versus activate.
            const current = await this.refresh();
            if (!this._alive || !current) return;
            if (!current.ready) {
              await this._installer.setup(current.adapter_setup, (phase) => {
                if (this._alive) this.installPhase = phase;
              });
              if (!this._alive) return;
              this.installPhase = "checking";
              // Activation loads the plugin in a fresh request, not this page.
              const status = await this.refresh();
              if (!this._alive || !status) return;
              if (!status.ready)
                throw new Error(
                  status.adapter_setup?.message ||
                    config.adapterSetup.labels.failed,
                );
            }
            this.message = this.enabled
              ? config.adapterSetup.labels.configured
              : config.adapterSetup.labels.success;
          } catch (error) {
            if (this._alive && error.name !== "AbortError") {
              // Installation can succeed while activation fails. Offer the
              // remaining action instead of trying to install the plugin again.
              await this.refresh();
              if (this._alive) this.error = error.message;
            }
          } finally {
            if (this._alive) this.installing = false;
          }
        },
        open() {
          if (!this.enabled || this.saving || this.loading || this.installing)
            return;
          this.message = "";
          this.opened = true;
        },
        close(restoreFocus = true) {
          this.opened = false;
          if (restoreFocus && this._alive)
            this.$nextTick(() => {
              const target = this.enabled
                ? this.$refs.configure
                : this.$refs.root;
              if (target && target.isConnected) target.focus();
            });
        },
      },
      template: `
        <div class="directorist-mcp-field" ref="root" tabindex="-1">
            <div class="directorist-mcp-card">
                <div><div class="directorist-mcp-title"><h3>{{ label || 'MCP for AI Agents' }}</h3><span class="directorist-mcp-badge" :class="{ 'is-active': enabled && ready }">{{ badge }}</span></div><p>{{ description }}</p></div>
                <div class="directorist-mcp-actions"><button v-if="enabled" ref="configure" type="button" class="directorist-mcp-button" :disabled="saving || loading || installing" @click="open">Configure</button>
                    <label class="directorist-mcp-switch"><input type="checkbox" role="switch" aria-label="Enable Directorist MCP" :checked="enabled" :disabled="saving || loading || installing || (!ready && !enabled)" @change="changeEnabled"><span aria-hidden="true"></span></label>
                    <span v-if="saving" role="status">Saving…</span>
                </div>
            </div>
            <div v-if="!ready && status" class="directorist-mcp-setup" :aria-busy="installing ? 'true' : 'false'">
                <p class="directorist-mcp-note">WordPress 6.9+ and an active compatible WordPress MCP Adapter are required.</p>
                <button v-if="adapterSetup && adapterSetup.action" type="button" class="directorist-mcp-link directorist-mcp-adapter-action" :aria-label="adapterLabel" :disabled="installing || loading || saving || !adapterSetup.can_setup" @click="setupAdapter"><span v-if="installing" class="directorist-mcp-spinner" aria-hidden="true"></span><span role="status">{{ adapterLabel }}</span></button>
                <p v-if="adapterSetup && adapterSetup.message" class="directorist-mcp-note">{{ adapterSetup.message }}</p>
            </div>
            <p v-if="error" role="alert" class="directorist-mcp-error">{{ error }} <button type="button" class="directorist-mcp-link" :disabled="installing || loading" @click="refresh">Retry</button></p>
            <p v-if="message && !opened" role="status">{{ message }}</p>
            <connection-drawer v-if="opened" :status="status" :enabled="enabled" :refresh-status="refresh" :error="error" @close="close" />
        </div>`,
    };
  };
})((window.DirectoristMcp = window.DirectoristMcp || {}));

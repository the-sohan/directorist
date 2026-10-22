/* Directorist MCP: components/client-configuration.js. Directly shipped source; no build required. */
(function (mcp) {
  "use strict";
  mcp.createClientConfiguration = function () {
    return {
      name: "McpClientConfiguration",
      props: {
        clients: Array,
        client: String,
        headingId: String,
        templatesLoading: Boolean,
        templatesError: String,
        credentialsReady: Boolean,
        snippet: String,
      },
      computed: {
        selectedClient() {
          return this.clients.find((item) => item.id === this.client) || null;
        },
      },
      methods: {
        tabKey(event, index) {
          let next = index;
          if (event.key === "ArrowRight")
            next = (index + 1) % this.clients.length;
          else if (event.key === "ArrowLeft")
            next = (index - 1 + this.clients.length) % this.clients.length;
          else if (event.key === "Home") next = 0;
          else if (event.key === "End") next = this.clients.length - 1;
          else return;
          event.preventDefault();
          this.$emit("select-client", this.clients[next].id);
          this.$nextTick(() => this.$refs.tabs[next].focus());
        },
      },
      template: `<div>                            <p v-if="templatesLoading" role="status">Loading client configuration…</p><p v-if="templatesError" role="alert" class="directorist-mcp-error">{{ templatesError }} <button type="button" @click="$emit('load-templates')">Retry</button></p>
                            <button v-if="!clients.length && !templatesLoading" type="button" class="directorist-mcp-button" @click="$emit('load-templates')">Load client configuration</button>
                            <div v-if="clients.length" class="directorist-mcp-tabs" role="tablist" aria-label="MCP client"><button v-for="(item, index) in clients" :key="item.id" ref="tabs" type="button" role="tab" :id="headingId + '-tab-' + item.id" :aria-controls="headingId + '-client'" :aria-selected="client === item.id ? 'true' : 'false'" :tabindex="client === item.id ? 0 : -1" @click="$emit('select-client', item.id)" @keydown="tabKey($event, index)">{{ item.label }}</button></div>
                            <div v-if="selectedClient" role="tabpanel" :id="headingId + '-client'" :aria-labelledby="headingId + '-tab-' + client"><p>{{ selectedClient.instructions }}</p><p v-if="!credentialsReady" class="directorist-mcp-note">Enter both values to fill the snippet locally, or replace its placeholders in your client.</p><pre class="directorist-mcp-snippet" tabindex="0">{{ snippet }}</pre><button type="button" class="directorist-mcp-button is-primary" :disabled="!snippet" @click="$emit('copy-snippet')">Copy snippet</button><p class="directorist-mcp-note">A filled snippet contains your application password. Keep it private. Closing this panel clears its password input; revoke credentials from your WordPress profile to disconnect a client.</p></div>
</div>`,
    };
  };
})((window.DirectoristMcp = window.DirectoristMcp || {}));

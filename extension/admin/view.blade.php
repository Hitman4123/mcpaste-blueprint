<form action="" method="POST" autocomplete="off">
  {{ csrf_field() }}
  <input type="hidden" name="_method" value="PATCH">

  <div class="row">
    <div class="col-xs-12 col-md-6">
      <div class="box box-primary">
        <div class="box-header with-border">
          <h3 class="box-title">Where pastes go</h3>
        </div>
        <div class="box-body">
          <div class="form-group">
            <label class="control-label" for="mcpaste-domain">MCPASTE_DOMAIN</label>
            <input type="text" class="form-control" id="mcpaste-domain" name="domain"
                   value="{{ $domain }}" placeholder="{{ $defaultDomain }}">
            <p class="text-muted small">
              The MCPaste site to post to. Leave it as {{ $defaultDomain }}, or use your own
              domain if you point one at MCPaste or run your own copy.
            </p>
          </div>

          <div class="form-group">
            <label class="control-label" for="mcpaste-token">MCPASTE_TOKEN</label>
            <input type="password" class="form-control" id="mcpaste-token" name="token" value=""
                   placeholder="{{ $tokenHint ? 'Saved: ' . $tokenHint . ' (leave empty to keep it)' : 'mcp_live_…' }}"
                   autocomplete="new-password" spellcheck="false">
            <p class="text-muted small">
              An API key from <strong>API keys</strong> on MCPaste. Make one just for this panel and give
              it this panel's hostname. The key is stored encrypted on the panel and is never sent to
              anyone's browser. Every paste from this panel lands in that MCPaste account.
            </p>
            @if($tokenHint)
              <div class="checkbox">
                <label><input type="checkbox" name="clear_token" value="1"> Remove the saved key (the Share log button stops working)</label>
              </div>
            @endif
          </div>
        </div>
      </div>
    </div>

    <div class="col-xs-12 col-md-6">
      <div class="box box-info">
        <div class="box-header with-border">
          <h3 class="box-title">What gets attached</h3>
        </div>
        <div class="box-body">
          <p class="small">
            Every shared log starts with the server's name, node, state, CPU, RAM and disk use, Docker image and egg,
            so support doesn't have to ask.
          </p>
          <div class="checkbox">
            <label>
              <input type="checkbox" name="owner_email" value="1" @if($ownerEmail) checked @endif>
              Also attach the server owner's email address
            </label>
          </div>
          <p class="text-muted small">
            Off by default. Anyone with a paste's link can read it, so only turn this on if your pastes stay private
            to your support team.
          </p>
        </div>
        <div class="box-footer">
          <button type="submit" class="btn btn-primary btn-sm">Save and check the connection</button>
        </div>
      </div>
    </div>
  </div>
</form>

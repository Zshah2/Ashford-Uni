<?php
/** @var array<string, mixed> $selfAccount */
/** @var string $csrf */
/** @var bool $isAdmin */
$selfAccount = $selfAccount ?? [];
$isAdmin = (bool)($isAdmin ?? false);
$selfEmail = (string)($selfAccount['email'] ?? '');
$selfName = trim((string)($selfAccount['display_name'] ?? ''));
if ($selfName === '') {
    $selfName = auth_resolve_display_name($selfAccount);
}
?>
<section class="mt-8 <?= htmlspecialchars(ui_card('p-6')) ?>">
  <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Your login</h2>
  <p class="mt-1 <?= htmlspecialchars(ui_muted()) ?>"><?= auth_is_viewer() ? 'View only. This account cannot change its login.' : 'Name, email, and password used to sign in.' ?></p>
<?php if (auth_is_viewer()): ?>
  <dl class="mt-5 max-w-md space-y-3 text-sm">
    <div>
      <dt class="<?= htmlspecialchars(ui_label()) ?>">Your name</dt>
      <dd class="mt-1 text-slate-900 dark:text-white"><?= htmlspecialchars($selfName) ?></dd>
    </div>
    <div>
      <dt class="<?= htmlspecialchars(ui_label()) ?>">Sign-in email</dt>
      <dd class="mt-1 text-slate-900 dark:text-white"><?= htmlspecialchars($selfEmail) ?></dd>
    </div>
  </dl>
<?php else: ?>
  <form class="mt-5 max-w-md space-y-4" method="post" action="<?= htmlspecialchars(url('/admin.php?view=settings')) ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
    <input type="hidden" name="action" value="auth_self_creds_save" />
    <div>
      <label class="<?= htmlspecialchars(ui_label()) ?>" for="self_display_name">Your name</label>
      <input
        id="self_display_name"
        name="display_name"
        type="text"
        required
        autocomplete="name"
        value="<?= htmlspecialchars($selfName) ?>"
        class="<?= htmlspecialchars(ui_input()) ?>"
      />
    </div>
    <div>
      <label class="<?= htmlspecialchars(ui_label()) ?>" for="self_email">Sign-in email</label>
      <input
        id="self_email"
        name="email"
        type="email"
        required
        autocomplete="email"
        value="<?= htmlspecialchars($selfEmail) ?>"
        class="<?= htmlspecialchars(ui_input()) ?>"
      />
    </div>
    <div>
      <label class="<?= htmlspecialchars(ui_label()) ?>" for="self_current_password">Current password</label>
      <input
        id="self_current_password"
        name="current_password"
        type="password"
        required
        autocomplete="current-password"
        class="<?= htmlspecialchars(ui_input()) ?>"
      />
    </div>
    <div>
      <label class="<?= htmlspecialchars(ui_label()) ?>" for="self_new_password">New password <span class="font-normal normal-case text-slate-400">(optional)</span></label>
      <input
        id="self_new_password"
        name="new_password"
        type="password"
        autocomplete="new-password"
        minlength="8"
        placeholder="Leave blank to keep current"
        class="<?= htmlspecialchars(ui_input()) ?>"
      />
    </div>
    <div>
      <label class="<?= htmlspecialchars(ui_label()) ?>" for="self_confirm_password">Confirm new password</label>
      <input
        id="self_confirm_password"
        name="confirm_password"
        type="password"
        autocomplete="new-password"
        minlength="8"
        class="<?= htmlspecialchars(ui_input()) ?>"
      />
    </div>
    <button type="submit" class="<?= htmlspecialchars(ui_btn_primary()) ?>">Save your login</button>
  </form>
<?php endif; ?>
</section>

<?php if ($isAdmin): ?>
  <p class="mt-4 <?= htmlspecialchars(ui_muted()) ?>">
    To change other users’ logins, open
    <a class="<?= htmlspecialchars(ui_link()) ?>" href="<?= htmlspecialchars(url('/admin.php?view=accounts')) ?>">Accounts</a>.
  </p>
<?php endif; ?>

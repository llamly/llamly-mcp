<?php
/** @var array<string, mixed> $consent */
if (!defined('ABSPATH')) {
	exit;
}
// Markup from the approved consent design (request and error states). The page runs no JS (CSP without script-src):
// "Full access" and "Read only" are a grant_scopes[] radio and read access always goes in a hidden field,
// so the server receives the same scopes as before and still validates everything in completeConsent().
// With $consent['error'] the same card shows the error (no form, no OAuth code): OAuthServer::consentError().
$llamlyError = isset($consent['error']) && is_array($consent['error']) ? $consent['error'] : null;
$llamlyInitial = (string) $consent['site_name'];
if (function_exists('mb_substr')) {
	$llamlyInitial = mb_substr($llamlyInitial, 0, 1);
} else {
	$llamlyInitial = preg_match('/./u', $llamlyInitial, $llamlyChar) === 1 ? $llamlyChar[0] : substr($llamlyInitial, 0, 1);
}
$llamlyMark = LLAMLY_FREE_ASSET_URL . 'assets/brand/llamly-mark.svg';
$llamlyLang = str_replace('_', '-', is_user_logged_in() ? get_user_locale() : determine_locale());
?>
<!doctype html>
<html lang="<?php echo esc_attr($llamlyLang); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="referrer" content="no-referrer">
	<title><?php echo esc_html(sprintf(/* translators: %s: site name. */ __('Authorize access ‹ %s', 'llamly'), $consent['site_name'])); ?></title>
	<?php wp_print_styles(['llamly-free-consent']); ?>
</head>
<body class="ll-consent-body">
	<div class="llamly">
	<main class="ll-consent">
		<header class="ll-consent__site">
			<div class="ll-consent__avatar" aria-hidden="true"><?php echo esc_html($llamlyInitial); ?></div>
			<div class="ll-consent__sitename"><?php echo esc_html($consent['site_name']); ?></div>
			<div class="ll-faint"><?php echo esc_html($consent['site_host']); ?></div>
		</header>
<?php if ($llamlyError !== null) : ?>
		<div class="ll-consent__card" role="alert" aria-labelledby="ll-consent-title">
			<div class="ll-consent__bye">
				<span class="ll-consent__badge ll-consent__badge--danger"><span class="ll-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span></span>
				<h1 class="ll-consent__title" id="ll-consent-title"><?php echo esc_html__('Couldn\'t connect', 'llamly'); ?></h1>
				<p class="ll-consent__sub"><?php echo esc_html(trim((string) $llamlyError['message'] . ' ' . (string) ($llamlyError['guidance'] ?? ''))); ?></p>
				<?php if (!empty($llamlyError['detail'])) : ?>
					<p class="ll-faint"><?php echo esc_html((string) $llamlyError['detail']); ?></p>
				<?php endif; ?>
				<?php if (!empty($llamlyError['switch_url'])) : ?>
					<a class="ll-btn" href="<?php echo esc_url((string) $llamlyError['switch_url']); ?>"><?php echo esc_html__('Switch account', 'llamly'); ?></a>
				<?php endif; ?>
				<div class="ll-consent__by"><img src="<?php echo esc_url($llamlyMark); ?>" alt=""><span><?php echo esc_html__('Protected by Llamly', 'llamly'); ?></span></div>
			</div>
		</div>
<?php else :
	$llamlyCanRead = !empty($consent['can_read']);
	$llamlyCanWrite = !empty($consent['can_write']);
	$llamlyRedirect = (string) ($consent['request']['redirect_uri'] ?? '');
	$llamlyRetry = add_query_arg(array_map('rawurlencode', array_map('strval', (array) $consent['request'])), (string) $consent['action']);
	$llamlySwitchUrl = wp_logout_url($llamlyRetry);
	?>
		<form class="ll-consent__card ll-consent__form" method="post" action="<?php echo esc_url($consent['action']); ?>" aria-labelledby="ll-consent-title">
			<?php echo $consent['nonce_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php foreach ($consent['request'] as $llamlyName => $llamlyValue) : ?>
				<input type="hidden" name="<?php echo esc_attr($llamlyName); ?>" value="<?php echo esc_attr((string) $llamlyValue); ?>">
			<?php endforeach; ?>
			<div class="ll-consent__pair" aria-hidden="true">
				<span class="ll-consent__logo"><img src="<?php echo esc_url($consent['client_icon']); ?>" alt=""></span>
				<span class="ll-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3 4 7l4 4M4 7h16M16 21l4-4-4-4M20 17H4"/></svg></span>
				<span class="ll-consent__logo"><img src="<?php echo esc_url($llamlyMark); ?>" alt=""></span>
			</div>
			<div class="ll-consent__head">
				<h1 class="ll-consent__title" id="ll-consent-title"><?php
					echo wp_kses(sprintf(/* translators: 1: app name, 2: site name */ __('<strong>%1$s</strong> wants to access %2$s', 'llamly'), esc_html($consent['client_label']), esc_html($consent['site_name'])), ['strong' => []]);
				?></h1>
				<p class="ll-consent__sub"><?php echo esc_html($llamlyCanWrite
					? __('It will be able to read and edit the site through Llamly, acting with your account\'s content permissions: posts and pages, media, menus, comments, patterns, templates and styles, and it can look up users. With safe mode on, the app must confirm deletions, undoing a change and changes that can\'t be undone. Almost every change is recorded in Activity and can be undone there. If you turn on approval in Llamly › Settings, publishing waits for you.', 'llamly')
					: __('It will be able to view the site through Llamly, acting with your account\'s content permissions.', 'llamly')); ?></p>
				<p class="ll-consent__note"><?php echo esc_html__('It never gets administrator powers, whatever your role: no site settings, users, plugins, themes, code files or unfiltered HTML. The app connects straight to this site; there is no Llamly server in between.', 'llamly'); ?></p>
			</div>
			<div class="ll-consent__user">
				<span><?php echo esc_html__('Signed in as', 'llamly'); ?> <strong><?php echo esc_html($consent['user_name']); ?></strong></span>
				<span class="ll-faint" aria-hidden="true">·</span>
				<a href="<?php echo esc_url($llamlySwitchUrl); ?>"><?php echo esc_html__('Switch account', 'llamly'); ?></a>
			</div>
			<?php if ($llamlyCanRead) : ?>
				<input type="hidden" name="grant_scopes[]" value="llamly.read">
			<?php endif; ?>
			<fieldset class="ll-consent__fieldset">
				<legend class="ll-consent__label"><?php echo esc_html__('Access level', 'llamly'); ?></legend>
				<div class="ll-choice">
					<label class="ll-choice__item"<?php echo $llamlyCanWrite ? '' : ' aria-disabled="true"'; ?>>
						<input type="radio" name="grant_scopes[]" value="llamly.write"<?php echo $llamlyCanWrite ? ' checked' : ' disabled'; ?>>
						<span class="ll-choice__body"><span class="ll-choice__title"><?php echo esc_html__('Full access', 'llamly'); ?></span><span class="ll-choice__desc"><?php echo esc_html($llamlyCanWrite ? __('Read and edit the site', 'llamly') : __('Unavailable: this app did not ask for write access, or writing is paused', 'llamly')); ?></span></span>
					</label>
					<label class="ll-choice__item"<?php echo $llamlyCanRead ? '' : ' aria-disabled="true"'; ?>>
						<input type="radio" name="grant_scopes[]" value="llamly.read"<?php echo $llamlyCanRead ? ($llamlyCanWrite ? '' : ' checked') : ' disabled'; ?>>
						<span class="ll-choice__body"><span class="ll-choice__title"><?php echo esc_html__('Read only', 'llamly'); ?></span><span class="ll-choice__desc"><?php echo esc_html__('View without changing anything', 'llamly'); ?></span></span>
					</label>
				</div>
			</fieldset>
			<div class="ll-callout ll-callout--warning"><span class="ll-icon" aria-hidden="true"><?php echo $consent['warning_icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><div class="ll-callout__body"><span><?php
				echo wp_kses(sprintf(/* translators: %s: redirect host */ __('After you approve, you go back to <strong>%s</strong>. The app name comes from the verified return address; approve only if you started this connection just now.', 'llamly'), esc_html($consent['redirect_host'])), ['strong' => []]);
			?></span></div></div>
			<div class="ll-consent__actions">
				<button class="ll-btn" type="submit" name="decision" value="deny"><?php echo esc_html__('Deny', 'llamly'); ?></button>
				<button class="ll-btn ll-btn--primary" type="submit" name="decision" value="approve"><?php echo esc_html__('Approve access', 'llamly'); ?></button>
			</div>
			<div class="ll-consent__foot">
				<div><?php echo esc_html__('Redirects to', 'llamly'); ?> <span class="ll-mono"><?php echo esc_html($llamlyRedirect); ?></span></div>
				<div><?php echo esc_html__('You can revoke this access at any time in Llamly > Connections.', 'llamly'); ?></div>
				<div class="ll-consent__by"><img src="<?php echo esc_url($llamlyMark); ?>" alt=""><span><?php echo esc_html__('Protected by Llamly', 'llamly'); ?></span></div>
			</div>
		</form>
<?php endif; ?>
	</main>
	</div>
</body>
</html>

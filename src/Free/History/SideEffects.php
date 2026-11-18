<?php

declare(strict_types=1);

namespace Llamly\Free\History;

/**
 * Scoped suppressor of outgoing e-mail while an adapter applies or restores a change (comment notifications, moderation
 * alerts...). E-mail cannot be undone, so it must not leave while the change can still be rolled back. Effects this
 * class cannot stop (remote anti-spam calls, webhooks) are the adapter's job to list in risk.external_effects.
 *
 * Reusable by any domain: SideEffects::mailSilenced(fn () => ...). Scopes nest; hooks are removed on exit even when
 * the callback throws.
 */
final class SideEffects
{
	private static int $depth = 0;
	private static int $blocked = 0;

	/** @template T @param callable():T $callback @return T */
	public static function mailSilenced(callable $callback): mixed
	{
		self::enter();
		try {
			return $callback();
		} finally {
			self::leave();
		}
	}

	/** Mails swallowed since the process started (diagnostics and tests). */
	public static function blockedMailCount(): int
	{
		return self::$blocked;
	}

	public static function active(): bool
	{
		return self::$depth > 0;
	}

	private static function enter(): void
	{
		if (self::$depth++ > 0) {
			return;
		}
		add_filter('notify_post_author', '__return_false', PHP_INT_MAX);
		add_filter('notify_moderator', '__return_false', PHP_INT_MAX);
		add_filter('pre_option_comments_notify', [self::class, 'zero'], PHP_INT_MAX);
		add_filter('pre_option_moderation_notify', [self::class, 'zero'], PHP_INT_MAX);
		add_filter('comment_notification_recipients', [self::class, 'none'], PHP_INT_MAX);
		add_filter('comment_moderation_recipients', [self::class, 'none'], PHP_INT_MAX);
		// Last net for anything else that mails inside the scope.
		add_filter('pre_wp_mail', [self::class, 'swallow'], PHP_INT_MAX);
	}

	private static function leave(): void
	{
		if (--self::$depth > 0) {
			return;
		}
		self::$depth = 0;
		remove_filter('notify_post_author', '__return_false', PHP_INT_MAX);
		remove_filter('notify_moderator', '__return_false', PHP_INT_MAX);
		remove_filter('pre_option_comments_notify', [self::class, 'zero'], PHP_INT_MAX);
		remove_filter('pre_option_moderation_notify', [self::class, 'zero'], PHP_INT_MAX);
		remove_filter('comment_notification_recipients', [self::class, 'none'], PHP_INT_MAX);
		remove_filter('comment_moderation_recipients', [self::class, 'none'], PHP_INT_MAX);
		remove_filter('pre_wp_mail', [self::class, 'swallow'], PHP_INT_MAX);
	}

	public static function zero(): int
	{
		return 0;
	}

	/** @return array<int, string> */
	public static function none(): array
	{
		return [];
	}

	public static function swallow(): bool
	{
		self::$blocked++;
		return false;
	}
}

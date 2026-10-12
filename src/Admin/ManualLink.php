<?php
/**
 * The Guide link on the Plugins screen.
 *
 * @package AumLang
 */

namespace AumLang\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a link to this plugin's manual beside Deactivate.
 *
 * 🔴 **空字符串 = 那种语言还没发布，这时候一个链接都不加。**
 * 宁可没有这一行，也不要一个点过去是 404 的链接 —— 读者点它的那一刻，
 * 正是他需要帮助的那一刻。
 *
 * ⚠️ **正文的语言和截图的语言是两回事。** AumLang **一种翻译都没发**
 * （`languages/` 里只有 .pot），所以无论管理员把后台设成哪种语言，他看到的界面
 * 都是英文的。手册四语版的正文是翻过的，而四语共用同一套英文截图 ——
 * 那正好和他屏幕上的一致。把中日读者送去英文正文反而更差，而把 zh_TW 送去
 * zh-hans 是在猜。
 */
class ManualLink {

	/**
	 * Site locale segment → the manual's published address. Empty string = not published there.
	 *
	 * @var array<string, string>
	 */
	const URLS = array(
		'en'      => 'https://aumcreate.com/manual/aumlang',
		'zh-hans' => 'https://aumcreate.com/zh-hans/manual/aumlang',
		'zh-hant' => 'https://aumcreate.com/zh-hant/manual/aumlang',
		'ja'      => 'https://aumcreate.com/ja/manual/aumlang',
	);

	/**
	 * WordPress admin language → which of those to send them to.
	 *
	 * 写成数据而不是 if 分支：闸可以读它，检查每个值都是 URLS 里的键。
	 * 分支就得当代码去解析，而分支一改，闸就对不上了。
	 *
	 * @var array<string, string>
	 */
/*
	 * 跨线约定（2026-10-11，官网线）：
	 * 手册的 slug（aumviso/aumlang/aumcrawl/aumdigital）和那四个语言前缀
	 * （无前缀=en、/zh-hans/、/zh-hant/、/ja/）官网线承诺不改。
	 * 反过来——改这张映射表、加一种语言、或改上面的 URL，发版前必须先通知官网线，
	 * 否则后台这个链接会 404，而插件里没有任何东西能发现它 404。
	 */
	const WP_TO_SITE = array(
		'zh_CN' => 'zh-hans',
		'zh_TW' => 'zh-hant',
		'zh_HK' => 'zh-hant',
		'ja'    => 'ja',
	);

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'plugin_action_links_' . AUMLANG_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * The URL for this administrator, or '' when the manual is not published for them.
	 *
	 * `get_user_locale()` 而不是 `get_locale()`：插件列表是用**那位管理员自己**设的
	 * 语言读的，而那可以和站点语言不同。
	 *
	 * @return string
	 */
	private function url() {
		$locale = get_user_locale();
		$key    = isset( self::WP_TO_SITE[ $locale ] ) ? self::WP_TO_SITE[ $locale ] : 'en';

		return isset( self::URLS[ $key ] ) ? self::URLS[ $key ] : '';
	}

	/**
	 * Append the Guide link.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = $this->url();

		if ( '' === $url ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $url ),
			esc_html__( 'Guide', 'aumlang' )
		);

		return (array) $links;
	}
}

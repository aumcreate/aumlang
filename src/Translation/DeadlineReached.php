<?php
/**
 * Thrown when a round runs out of its time budget mid-chunk.
 *
 * @package AumLang
 */

namespace AumLang\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * 和「翻译失败」要分得开。
 *
 * 失败是结果不对，该让站长看见；到点是我们自己喊停，该默默留到下一轮继续。
 * 用一个独立的类型，调用方就不必去猜异常消息的内容 —— 猜消息迟早会猜错。
 */
class DeadlineReached extends \RuntimeException {
}

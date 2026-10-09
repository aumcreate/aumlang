/**
 * Diagnostics tab — measure this server, then say what it means.
 *
 * 🔴 两个数决定一切：
 *   A 网关肯等多少秒（梯子探出来的）
 *   B 一次 provider 调用要多少秒（真调一次量出来的）
 * B 接近或超过 A，分块再细也没用 —— 一轮的收工是在每块**开始前**判的，
 * 所以一轮最坏会超出预算整整一块。
 */
( function ( $ ) {
	'use strict';

	var D = window.AumLangDiag || {};
	var out = {};

	function say( text ) {
		$( '#aumlang-diag-status' ).text( text );
	}

	/** 一次 AJAX；失败时把原始响应也带回来，别让错误消息是空的。 */
	function post( action, data, timeoutMs ) {
		var d = $.extend( { action: action, nonce: D.nonce }, data || {} );

		return $.ajax( {
			url: D.ajaxUrl,
			type: 'POST',
			data: d,
			dataType: 'json',
			timeout: timeoutMs || 90000
		} ).then(
			function ( res ) {
				return res && res.success
					? { ok: true, data: res.data }
					: { ok: false, why: ( res && res.data && res.data.message ) || 'unknown' };
			},
			function ( jqXHR, textStatus ) {
				return $.Deferred().resolve( {
					ok: false,
					transport: true,
					status: jqXHR.status || 0,
					why: textStatus || 'error',
					body: ( jqXHR.responseText || '' ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, 300 )
				} ).promise();
			}
		);
	}

	/** 梯子：升序逐级，第一次失败就停，不白占买家的进程。 */
	function ladder( i, acc ) {
		if ( i >= D.ladder.length ) {
			out.gateway = acc;
			return $.Deferred().resolve().promise();
		}

		var secs = D.ladder[ i ];
		var t0 = Date.now();
		say( D.i18n.sleep.replace( '%d', secs ) );

		return post( 'aumlang_diag_sleep', { secs: secs }, ( secs + 25 ) * 1000 ).then( function ( r ) {
			var wall = Math.round( ( Date.now() - t0 ) / 100 ) / 10;

			acc.steps.push( { secs: secs, ok: r.ok, wall: wall, status: r.status, why: r.why, body: r.body } );

			if ( ! r.ok ) {
				acc.firstFail = secs;
				acc.failAt = wall;
				acc.failStatus = r.status;
				acc.failBody = r.body;
				out.gateway = acc;
				return $.Deferred().resolve().promise();
			}

			acc.lastOk = secs;
			return ladder( i + 1, acc );
		} );
	}

	/* 真实的一页，按轮翻到底。
	   🔴 只跑一轮没用：2026-10 有买家服务器挂 70 秒不掐、单次调用 1.4 秒，两项都
	   「正常」，真翻一页却 109 秒后 502 —— 故障只在整页跑完的过程里才现形。 */
	function realRound( id, n, rounds ) {
		if ( n > 25 ) { out.real = { rounds: rounds, stopped: 'too many rounds' }; return; }
		say( D.i18n.round.replace( '%d', n ) );

		var t0 = Date.now();

		return post( 'aumlang_diag_real', { post: id, lang: D.lang }, 180000 ).then( function ( r ) {
			var wall = Math.round( ( Date.now() - t0 ) / 100 ) / 10;
			var d = ( r.ok && r.data ) ? r.data : null;

			rounds.push( {
				n: n, wall: wall, ok: !! ( d && d.ok ), status: r.status, why: r.why, body: r.body,
				error: ( d && ! d.ok ) ? d.error : '',
				remaining: d ? d.remaining : null, done: d ? d.done : null
			} );

			if ( ! d || ! d.ok ) { out.real = { rounds: rounds, stopped: 'a round failed' }; return; }
			if ( d.done ) { out.real = { rounds: rounds, stopped: 'finished' }; return; }

			return realRound( id, n + 1, rounds );
		} );
	}

	function realRun() {
		var id = parseInt( $( '#aumlang-diag-id' ).val(), 10 )
			|| parseInt( $( '#aumlang-diag-post' ).val(), 10 ) || 0;
		if ( ! id || ! D.lang ) { return; }
		return realRound( id, 1, [] );
	}

	function runCalls() {
		say( D.i18n.call );

		return post( 'aumlang_diag_call', { n: 1 }, 120000 ).then( function ( one ) {
			out.call1 = one;

			var batch = ( out.env && out.env.batchSize ) || 1;
			if ( batch <= 1 ) {
				return;
			}

			return post( 'aumlang_diag_call', { n: batch }, 120000 ).then( function ( many ) {
				out.callN = many;
			} );
		} );
	}

	/* ---- 结论 ---- */

	function secsOfCall( c ) {
		return c && c.ok && c.data ? c.data.took : null;
	}

	function verdict() {
		var env = out.env || {};
		var g = out.gateway || { steps: [] };
		var lines = [];
		var level = 'ok';

		var chunk = secsOfCall( out.callN ) !== null ? secsOfCall( out.callN ) : secsOfCall( out.call1 );
		var limit = g.firstFail ? g.lastOk : null;   /* 确切上限在 lastOk 和 firstFail 之间 */
		var highest = D.ladder[ D.ladder.length - 1 ];

		/* 真的被掐断的那一轮是最硬的证据，结论以它为准。
		   只看挂起梯子，会在一条 502 正下方写出「时间上够用」。 */
		var cut = null;
		( ( ( out.real || {} ).rounds ) || [] ).forEach( function ( r ) {
			if ( ! r.ok && ( r.status || r.why ) ) { cut = r; }
		} );

		if ( cut ) {
			lines.push( '真翻一页确实失败了：第 ' + cut.n + ' 轮跑到 ' + cut.wall + ' 秒被掐断'
				+ ( cut.status ? '（HTTP ' + cut.status + '）' : '' ) + '。' );

			if ( limit === null ) {
				lines.push( '挂起测试到 ' + highest + ' 秒都没被掐，所以这台服务器的上限在 '
					+ highest + ' 秒和 ' + cut.wall + ' 秒之间 —— 单看够用，扛不住一次把整页做完的请求。' );
			} else {
				lines.push( '这台服务器在 ' + g.lastOk + '–' + g.firstFail + ' 秒之间停止等待。' );
			}

			lines.push( '' );
			lines.push( '每轮预算 ' + ( env.round || 20 ) + ' 秒，一次调用 '
				+ ( chunk === null ? '没量到' : chunk + ' 秒' ) + '，一轮却跑了 ' + cut.wall
				+ ' 秒。请把下面整段报告发给我们 —— 这个组合不该出现。' );

			var miscount = '';
			[ out.call1, out.callN ].forEach( function ( c ) {
				var d = ( c && c.ok && c.data ) ? c.data : null;
				if ( d && d.ok === false && /items for/.test( String( d.error || '' ) ) ) { miscount = d.error; }
			} );

			if ( miscount ) {
				lines.push( '' );
				lines.push( '另外：翻译服务商有时会少返回几条（「' + miscount
					+ '」）。插件会重试并把这一批对半拆开接着做，结果是请求次数成倍增加，'
					+ '这也是一页要跑这么久的原因之一。' );
			}

			return { level: 'bad', lines: lines };
		}

		/* 1. 服务商自己就报错 —— 这才是真因，和超时无关。 */
		var bad = ( out.call1 && out.call1.ok && out.call1.data && out.call1.data.ok === false ) ? out.call1.data : null;
		if ( bad ) {
			level = 'bad';
			lines.push( '翻译服务商直接返回了错误，这和服务器超时无关：' );
			lines.push( '  ' + ( bad.error || bad.code ) );
			if ( bad.detail ) {
				lines.push( '  ' + bad.detail );
			}
			return { level: level, lines: lines };
		}

		if ( out.call1 && ! out.call1.ok ) {
			level = 'bad';
			lines.push( '连一次最小的翻译调用都没能完成。' );
			if ( out.call1.status ) {
				lines.push( '  HTTP ' + out.call1.status + ( out.call1.body ? ' — ' + out.call1.body : '' ) );
			}
			return { level: level, lines: lines };
		}

		/* 2. 网关 vs 一块的耗时。 */
		if ( ! g.firstFail ) {
			lines.push( '服务器愿意等 ' + D.ladder[ D.ladder.length - 1 ] + ' 秒以上，没有探到上限。' );
		} else {
			lines.push( '服务器在 ' + g.lastOk + '–' + g.firstFail + ' 秒之间掐断请求'
				+ ( g.failStatus ? '（返回 HTTP ' + g.failStatus + '）' : '' ) + '。' );
			if ( env.maxExec && env.maxExec > 0 && g.firstFail <= env.maxExec ) {
				lines.push( '  这个上限比 PHP 的 max_execution_time（' + env.maxExec + ' 秒）还低，'
					+ '所以掐断的是 PHP 前面的网关或代理，不是 PHP 本身。' );
			}
		}

		if ( chunk === null ) {
			lines.push( '没能量到一次翻译调用的耗时。' );
			return { level: 'bad', lines: lines };
		}

		lines.push( '一次翻译调用（' + ( ( out.callN && out.callN.data && out.callN.data.n ) || 1 ) + ' 段文字）耗时 ' + chunk + ' 秒。' );

		if ( limit !== null && chunk >= limit ) {
			level = 'bad';
			lines.push( '' );
			lines.push( '🔴 分块解决不了这台服务器的问题：一次调用就要 ' + chunk + ' 秒，'
				+ '而服务器只肯等约 ' + limit + ' 秒。无论切多细，最小的那一块都超时。' );
			lines.push( '可以做的：换一个更快的模型（同一家服务商往往有快慢两档），'
				+ '或者请主机把 nginx 的 fastcgi_read_timeout（Apache 是 ProxyTimeout）提到 120 秒。' );
		} else if ( limit !== null && ( env.round || 20 ) + chunk > limit ) {
			level = 'warn';
			lines.push( '' );
			lines.push( '⚠️ 每轮预算 ' + ( env.round || 20 ) + ' 秒，加上最后一块可能多跑 ' + chunk
				+ ' 秒，最坏 ' + ( ( env.round || 20 ) + chunk ) + ' 秒 —— 超过服务器的 ' + limit + ' 秒。'
				+ '偶尔成功、偶尔 502，就是这么来的。' );
			lines.push( '可以做的：把每轮预算调到 ' + Math.max( 5, Math.floor( limit - chunk ) ) + ' 秒以内，或者把超时提上去。' );
		} else {
			lines.push( '' );
			lines.push( '✅ 时间上够用：每轮 ' + ( env.round || 20 ) + ' 秒 + 最后一块 ' + chunk
				+ ' 秒，仍在服务器愿意等的范围内。502 如果还在发生，原因不在超时，请把下面整段报告发回来。' );
		}

		if ( env.stuck ) {
			lines.push( '' );
			lines.push( '另外：有 ' + env.stuck + ' 个翻译任务停在半截（上次被掐断留下的）。重新翻译会从断点接着跑。' );
		}

		return { level: level, lines: lines };
	}

	function report( v ) {
		var env = out.env || {};
		var g = out.gateway || { steps: [] };
		var L = [];

		L.push( '=== AumLang 诊断报告 ===' );
		L.push( '站点      ' + D.site );
		L.push( '时间      ' + new Date().toISOString() );
		L.push( '' );
		L.push( 'AumLang   ' + env.plugin + '    WordPress ' + env.wp + '    PHP ' + env.php );
		L.push( '服务器    ' + ( env.server || '(未知)' ) );
		L.push( '代理层    ' + ( env.proxy && env.proxy.length ? env.proxy.join( ', ' ) : '(没有检测到)' ) );
		L.push( 'PHP 限制  max_execution_time=' + env.maxExec + '  memory_limit=' + env.memory );
		L.push( '服务商    ' + ( env.provider || '(未设置)' ) + '  已配置=' + ( env.configured ? '是' : '否' )
			+ '  每批=' + env.batchSize + '  每轮预算=' + env.round + '秒' );
		L.push( '半截任务  ' + env.stuck );
		L.push( '' );
		L.push( '--- 服务器肯等多久 ---' );
		g.steps.forEach( function ( s ) {
			L.push( '  ' + ( s.ok ? '通过' : '失败' ) + '  请求挂 ' + s.secs + ' 秒 → 实际 ' + s.wall + ' 秒'
				+ ( s.ok ? '' : '  ' + ( s.status ? 'HTTP ' + s.status + ' ' : '' ) + ( s.why || '' ) ) );
			if ( ! s.ok && s.body ) {
				L.push( '        ' + s.body );
			}
		} );
		L.push( '' );
		L.push( '--- 翻译调用耗时 ---' );
		[ out.call1, out.callN ].forEach( function ( c ) {
			if ( ! c ) { return; }
			if ( c.ok && c.data ) {
				L.push( '  ' + c.data.n + ' 段 → ' + c.data.took + ' 秒  ' + ( c.data.ok ? '成功' : '失败: ' + c.data.error ) );
				if ( c.data.ok === false && c.data.detail ) {
					L.push( '        ' + c.data.detail );
				}
			} else {
				L.push( '  调用失败  ' + ( c.status ? 'HTTP ' + c.status + ' ' : '' ) + ( c.why || '' ) );
				if ( c.body ) { L.push( '        ' + c.body ); }
			}
		} );
		if ( out.real ) {
			L.push( '' );
			L.push( '--- 真翻一页，逐轮 ---' );
			L.push( '  停止原因: ' + out.real.stopped );
			( out.real.rounds || [] ).forEach( function ( r ) {
				if ( r.ok ) {
					L.push( '  第 ' + r.n + ' 轮: ' + r.wall + ' 秒   ' + ( r.done ? '完成' : '还剩 ' + r.remaining ) );
				} else if ( r.status || r.why ) {
					L.push( '  第 ' + r.n + ' 轮: ' + r.wall + ' 秒   被掐断  '
						+ ( r.status ? 'HTTP ' + r.status + ' ' : '' ) + ( r.why || '' ) );
					if ( r.body ) { L.push( '          ' + r.body ); }
				} else {
					L.push( '  第 ' + r.n + ' 轮: ' + r.wall + ' 秒   失败  ' + r.error );
				}
			} );
		}

		L.push( '' );
		L.push( '--- 结论 ---' );
		v.lines.forEach( function ( l ) { L.push( l ); } );

		return L.join( '\n' );
	}

	function finish() {
		var v = verdict();
		var colour = v.level === 'bad' ? '#d63638' : ( v.level === 'warn' ? '#996800' : '#007017' );

		$( '#aumlang-diag-verdict' ).html(
			'<div style="border-left:4px solid ' + colour + ';padding:8px 12px;margin:0 0 14px;background:#fff">'
			+ v.lines.map( function ( l ) {
				return '<p style="margin:4px 0">' + $( '<div>' ).text( l ).html() + '</p>';
			} ).join( '' ) + '</div>'
		);

		$( '#aumlang-diag-report' ).val( report( v ) );
		$( '#aumlang-diag-out' ).prop( 'hidden', false );
		say( D.i18n.done );
		$( '#aumlang-diag-run' ).prop( 'disabled', false );
	}

	$( document ).on( 'click', '#aumlang-diag-run', function () {
		$( this ).prop( 'disabled', true );
		out = {};
		$( '#aumlang-diag-out' ).prop( 'hidden', true );
		say( D.i18n.env );

		post( 'aumlang_diag_env', {}, 20000 )
			.then( function ( r ) {
				out.env = r.ok ? r.data : {};
				return ladder( 0, { steps: [], lastOk: 0 } );
			} )
			.then( runCalls )
			.then( realRun )
			.then( finish, finish );
	} );

	$( document ).on( 'click', '#aumlang-diag-copy', function () {
		var el = document.getElementById( 'aumlang-diag-report' );
		el.select();
		el.setSelectionRange( 0, 99999 );
		try {
			document.execCommand( 'copy' );
			say( D.i18n.copied );
		} catch ( e ) {
			/* 剪贴板不给用就算了，文本框本来就是可以手选的。 */
		}
	} );
}( jQuery ) );

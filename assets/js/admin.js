/**
 * A-Blog 后台设置页交互脚本
 * 功能：备用选题池（列表/新增/编辑/删除/排序/列入计划/立即完成）、今日计划任务（删除/立即完成/清空）、
 *       日志 AJAX 刷新、Token 复制、开关联动提示、表单保存提示。
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var abp = window.abpAdmin || {};

		/* ---------- 日志刷新（AJAX） ---------- */
		var refreshBtn = document.getElementById( 'abp-refresh-log' );
		var logBox = document.getElementById( 'abp-log-container' );
		var clearLogBtn = document.getElementById( 'abp-clear-log' );
		if ( refreshBtn && logBox ) {
			refreshBtn.addEventListener( 'click', function () {
				var oldText = refreshBtn.textContent;
				refreshBtn.disabled = true;
				refreshBtn.textContent = abp.logRefresh || '刷新中…';
				fetch( abp.ajaxUrl + '?action=abp_log_refresh&_ajax_nonce=' + encodeURIComponent( abp.logNonce ), { credentials: 'same-origin' } )
					.then( function ( res ) { return res.json(); } )
					.then( function ( data ) {
						if ( data && data.success && data.data && data.data.html ) {
							logBox.innerHTML = data.data.html;
						} else {
							logBox.innerHTML = '<p class="description">刷新失败：' + ( ( data && data.data ) ? data.data : '未知错误' ) + '</p>';
						}
					} )
					.catch( function () { logBox.innerHTML = '<p class="description">网络错误，请重试。</p>'; } )
					.finally( function () { refreshBtn.disabled = false; refreshBtn.textContent = oldText; } );
			} );
		}

		/* ---------- 日志清空 ---------- */
		if ( clearLogBtn ) {
			clearLogBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( '确定清空全部任务日志吗？此操作不可恢复。' ) ) { return; }
				clearLogBtn.disabled = true;
				fetch( abp.ajaxUrl + '?action=abp_log_clear&_ajax_nonce=' + encodeURIComponent( abp.logClearNonce ), { credentials: 'same-origin' } )
					.then( function ( res ) { return res.json(); } )
					.then( function ( data ) {
						if ( data && data.success ) {
							logBox.innerHTML = '<p class="description">日志已清空（' + ( ( data.data && data.data.deleted ) || 0 ) + ' 条）。</p>';
						} else {
							alert( '清空失败：' + ( ( data && data.data ) ? JSON.stringify( data.data ) : '未知错误' ) );
						}
					} )
					.catch( function () { alert( '网络错误，清空失败。' ); } )
					.finally( function () { clearLogBtn.disabled = false; } );
			} );
		}

		/* ---------- Token 复制 ---------- */
		var copyBtn = document.getElementById( 'abp-copy-token' );
		if ( copyBtn ) {
			copyBtn.addEventListener( 'click', function () {
				var target = document.getElementById( copyBtn.getAttribute( 'data-target' ) );
				if ( ! target ) { return; }
				target.select();
				target.setSelectionRange( 0, 99999 );
				var done = function () {
					var old = copyBtn.textContent;
					copyBtn.textContent = abp.copied || '已复制';
					window.setTimeout( function () { copyBtn.textContent = old; }, 1500 );
				};
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( target.value ).then( done ).catch( function () {
						document.execCommand( 'copy' ) ? done() : alert( abp.copyFailed || '复制失败，请手动选择复制' );
					} );
				} else if ( document.execCommand( 'copy' ) ) {
					done();
				} else {
					alert( abp.copyFailed || '复制失败，请手动选择复制' );
				}
			} );
		}

		/* ---------- 开关联动提示：配图关闭时淡化图片 API 配置区 ---------- */
		var imageSwitch = document.querySelector( 'input[name="abp_settings[image_enabled]"]' );
		var imageRows = [];
		var inputs = document.querySelectorAll( 'input[name^="abp_settings[image_api]"], input[name="abp_settings[models][image]"]' );
		inputs.forEach( function ( input ) {
			var tr = input.closest( 'tr' );
			if ( tr && imageRows.indexOf( tr ) === -1 ) { imageRows.push( tr ); }
		} );
		var applyImageHint = function () {
			var enabled = imageSwitch ? imageSwitch.checked : true;
			imageRows.forEach( function ( tr ) { if ( tr ) { tr.classList.toggle( 'abp-disabled-row', ! enabled ); } } );
		};
		if ( imageSwitch ) {
			imageSwitch.addEventListener( 'change', applyImageHint );
			applyImageHint();
		}

		/* ---------- 表单保存提示 ---------- */
		var form = document.getElementById( 'abp-settings-form' );
		if ( form ) {
			form.addEventListener( 'submit', function () {
				var btn = form.querySelector( '#submit' );
				if ( btn ) { btn.disabled = true; btn.value = '保存中…'; }
			} );
		}

		/* ================= 备用选题池（唯一操作台） ================= */
		var poolBox = document.getElementById( 'abp-pool-container' );
		var poolFillBtn = document.getElementById( 'abp-pool-fill' );
		var poolClearBtn = document.getElementById( 'abp-pool-clear' );
		var poolAddBtn = document.getElementById( 'abp-pool-add-btn' );
		var poolTopicInput = document.getElementById( 'abp-pool-topic' );
		var poolColSelect = document.getElementById( 'abp-pool-col' );
		var poolMsg = document.getElementById( 'abp-pool-msg' );
		var tokenInput = document.getElementById( 'abp-token' );
		var poolRest = abp.restUrl || '';

		var headers = function () {
			var h = { 'Content-Type': 'application/json' };
			if ( tokenInput && tokenInput.value ) { h.Authorization = 'Bearer ' + tokenInput.value; }
			return h;
		};

		var columnName = function ( c ) {
			return { stock: 'A股复盘', tech: 'IT技术', reading: '国学', book: '书评', industry: '行业综述' }[ c ] || c;
		};

		// 备选题池栏目下拉：现有文章分类（翁老：栏目在现有分类中选）
		var poolColSelect = document.getElementById( 'abp-pool-col' );
		if ( poolColSelect ) {
			fetch( abp.ajaxUrl + '?action=abp_pool_categories&_ajax_nonce=' + encodeURIComponent( abp.logNonce ), { credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( d ) {
					if ( d && d.success && d.data && d.data.length ) {
						poolColSelect.innerHTML = '';
						d.data.forEach( function ( name ) {
							var opt = document.createElement( 'option' );
							opt.value = name;
							opt.textContent = name;
							poolColSelect.appendChild( opt );
						} );
					}
				} )
				.catch( function () {} );
		}
		var sourceLabel = function ( s ) {
			return { ai: 'AI', manual: '人工', local: '素材' }[ s ] || s || '';
		};
		var statusLabel = function ( s ) {
			return { queued: '排队中', used: '已用' }[ s ] || s || '';
		};
		var esc = function ( s ) {
			var d = document.createElement( 'div' );
			d.textContent = s === null || s === undefined ? '' : String( s );
			return d.innerHTML;
		};

		var renderPool = function ( data ) {
			if ( ! data || ! data.topics || ! data.topics.length ) {
				poolBox.innerHTML = '<p class="description">备用选题池为空。下方手动添加，或由生成引擎在选题时自动补充候选入池。</p>';
				return;
			}
			var html = '<table class="widefat striped"><thead><tr><th>#</th><th>栏目</th><th>备用选题</th><th>来源</th><th>状态</th><th>操作</th></tr></thead><tbody>';
			var ids = [];
			data.topics.forEach( function ( t, i ) {
				ids.push( t.id );
				html += '<tr data-pool-id="' + t.id + '">' +
					'<td>' + ( i + 1 ) + '</td>' +
					'<td>' + esc( columnName( t.column_name ) ) + '</td>' +
					'<td class="abp-pool-topic-cell"><span class="abp-pool-topic-text">' + esc( t.topic ) + '</span>' +
					'<input type="text" class="abp-pool-topic-edit" value="' + esc( t.topic ) + '" style="display:none;width:90%" /></td>' +
					'<td>' + esc( sourceLabel( t.source ) ) + '</td>' +
					'<td>' + esc( statusLabel( t.status ) ) + '</td>' +
					'<td class="abp-pool-ops">' +
					'<button type="button" class="button button-small abp-pool-plan" data-id="' + t.id + '" title="列入今日计划排队，定时生成发布">列入计划</button> ' +
					'<button type="button" class="button button-small abp-pool-run" data-id="' + t.id + '" title="立即完成：列入计划并马上生成发布">立即完成</button> ' +
					'<button type="button" class="button button-small abp-pool-up" data-id="' + t.id + '">↑</button> ' +
					'<button type="button" class="button button-small abp-pool-down" data-id="' + t.id + '">↓</button> ' +
					'<button type="button" class="button button-small abp-pool-edit" data-id="' + t.id + '">编辑</button> ' +
					'<button type="button" class="button button-small abp-pool-save" data-id="' + t.id + '" style="display:none">保存</button> ' +
					'<button type="button" class="button button-small abp-pool-del" data-id="' + t.id + '">删除</button>' +
					'</td></tr>';
			} );
			html += '</tbody></table>';
			if ( data.recent_used && data.recent_used.length ) {
				html += '<p class="description">最近已用：' + data.recent_used.slice( 0, 5 ).map( function ( u ) { return esc( u.topic ); } ).join( '；' ) + '</p>';
			}
			poolBox.innerHTML = html;
			poolBox.setAttribute( 'data-pool-ids', JSON.stringify( ids ) );
		};

		var loadPool = function () {
			if ( ! poolRest || ! poolBox ) { return; }
			fetch( poolRest, { headers: headers(), credentials: 'same-origin' } )
				.then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
				.then( function ( d ) {
					if ( d && d.ok === false ) { poolBox.innerHTML = '<p class="description">加载失败：' + esc( d.error || ( d && ( d.message || d.detail ) ) || '未知错误' ) + '</p>'; }
					else { renderPool( d ); }
				} )
				.catch( function ( e ) { poolBox.innerHTML = '<p class="description">网络错误：' + esc( e && e.message ? e.message : e ) + '</p>'; } );
		};

		var poolReorder = function ( id, dir ) {
			var raw = poolBox.getAttribute( 'data-pool-ids' );
			var ids = raw ? JSON.parse( raw ) : [];
			var i = ids.indexOf( id );
			var j = i + dir;
			if ( i < 0 || j < 0 || j >= ids.length ) { return; }
			ids[ i ] = ids[ j ];
			ids[ j ] = id;
			fetch( poolRest + '/reorder', {
				method: 'POST', headers: headers(),
				body: JSON.stringify( { ids: ids } ), credentials: 'same-origin'
			} ).then( function ( r ) { return r.json(); } ).then( function ( d ) { if ( d && d.ok ) { loadPool(); } } ).catch( function () {} );
		};

		var poolMsgShow = function ( text, isErr ) {
			if ( poolMsg ) {
				poolMsg.textContent = text;
				poolMsg.style.color = isErr ? '#b32d2e' : '#2271b1';
			}
		};

		if ( poolBox ) { loadPool(); }

		if ( poolFillBtn ) {
			poolFillBtn.addEventListener( 'click', function () {
				poolFillBtn.disabled = true;
				fetch( poolRest + '/fill', {
					method: 'POST', headers: headers(),
					body: JSON.stringify( {} ), credentials: 'same-origin'
				} ).then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
					.then( function ( d ) {
						poolMsgShow( d && d.ok ? '已智能填充 ' + ( d.added || 0 ) + ' 条备用选题' + ( d.note ? '（' + d.note + '）' : '' ) : ( '填充失败：' + ( ( d && d.error ) || ( d && ( d.message || d.detail ) ) || '未知错误' ) ), ! ( d && d.ok ) );
						if ( d && d.ok ) { loadPool(); }
					} )
					.catch( function ( e ) { poolMsgShow( '网络错误：' + ( e && e.message ? e.message : e ), true ); } )
					.finally( function () { poolFillBtn.disabled = false; } );
			} );
		}

		if ( poolClearBtn ) {
			poolClearBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( '确定清空全部备用选题吗？清空后不可恢复，需重新填充。' ) ) { return; }
				poolClearBtn.disabled = true;
				fetch( poolRest + '/clear', {
					method: 'POST', headers: headers(), credentials: 'same-origin'
				} ).then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
					.then( function ( d ) {
						poolMsgShow( d && d.ok ? '已清空 ' + ( d.cleared || 0 ) + ' 条备用选题' : ( '清空失败：' + ( ( d && d.error ) || ( d && ( d.message || d.detail ) ) || '未知错误' ) ), ! ( d && d.ok ) );
						if ( d && d.ok ) { loadPool(); }
					} )
					.catch( function ( e ) { poolMsgShow( '网络错误：' + ( e && e.message ? e.message : e ), true ); } )
					.finally( function () { poolClearBtn.disabled = false; } );
			} );
		}

		if ( poolAddBtn ) {
			poolAddBtn.addEventListener( 'click', function () {
				var topic = poolTopicInput ? poolTopicInput.value.trim() : '';
				var col = poolColSelect ? poolColSelect.value : 'tech';
				if ( ! topic ) { poolMsgShow( '请输入选题', true ); return; }
				fetch( poolRest, {
					method: 'POST', headers: headers(),
					body: JSON.stringify( { column: col, topic: topic } ), credentials: 'same-origin'
				} ).then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
					.then( function ( d ) {
						if ( d && d.ok ) {
							var note = d.optimized ? '（已自动优化标题）' : '';
							poolMsgShow( '✅ 已加入池子' + note + '：' + ( d.item ? d.item.topic : '' ), false );
							if ( poolTopicInput ) { poolTopicInput.value = ''; }
							loadPool();
						} else {
							poolMsgShow( '加入失败：' + ( ( d && d.error ) || ( d && ( d.message || d.detail ) ) || '未知错误' ), true );
						}
					} )
					.catch( function ( e ) { poolMsgShow( '网络错误：' + ( e && e.message ? e.message : e ), true ); } );
			} );
		}

		/* GitHub 自动升级：检查更新 */
		var checkUpdateBtn = document.getElementById( 'abp-check-update' );
		var updateStatus = document.getElementById( 'abp-update-status' );
		if ( checkUpdateBtn ) {
			checkUpdateBtn.addEventListener( 'click', function () {
				checkUpdateBtn.disabled = true;
				if ( updateStatus ) {
					updateStatus.textContent = '检查中…';
					updateStatus.style.color = '';
				}
				fetch( abp.ajaxUrl + '?action=abp_check_update&_ajax_nonce=' + encodeURIComponent( abp.logNonce ), { credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( d ) {
						if ( d && d.success && d.data && d.data.ok ) {
							var v = d.data;
							if ( v.has_update ) {
								if ( updateStatus ) {
									updateStatus.innerHTML = '发现新版本 v' + esc( v.latest ) + '（当前 v' + esc( v.current ) + '）→ <a href="' + esc( v.update_url ) + '">去升级</a>';
									updateStatus.style.color = '#d63638';
								}
							} else if ( updateStatus ) {
								if ( v.stale ) {
									updateStatus.textContent = '远端仓库最新为 v' + v.latest + '，低于当前 v' + v.current + '（GitHub Release 未同步）';
									updateStatus.style.color = '#b32d2e';
								} else {
									updateStatus.textContent = '已是最新版本 v' + v.latest;
									updateStatus.style.color = '#00a32a';
								}
							}
						} else if ( updateStatus ) {
							updateStatus.textContent = ( d && d.data ) ? d.data : '检查失败';
							updateStatus.style.color = '#b32d2e';
						}
					} )
					.catch( function () { if ( updateStatus ) { updateStatus.textContent = '网络错误'; updateStatus.style.color = '#b32d2e'; } } )
					.finally( function () { checkUpdateBtn.disabled = false; } );
			} );
		}

		/* 行内操作（事件委托） */
		document.addEventListener( 'click', function ( ev ) {
			var el = ev.target;
			if ( ! el || ! el.classList ) { return; }
			var id = el.getAttribute ? el.getAttribute( 'data-id' ) : null;
			if ( ! id ) { return; }
			var row = el.closest( 'tr' );

			if ( el.classList.contains( 'abp-pool-up' ) ) { poolReorder( parseInt( id, 10 ), -1 ); }
			if ( el.classList.contains( 'abp-pool-down' ) ) { poolReorder( parseInt( id, 10 ), 1 ); }

			if ( el.classList.contains( 'abp-pool-edit' ) ) {
				if ( row ) {
					row.querySelector( '.abp-pool-topic-text' ).style.display = 'none';
					row.querySelector( '.abp-pool-topic-edit' ).style.display = '';
					row.querySelector( '.abp-pool-save' ).style.display = '';
				}
			}
			if ( el.classList.contains( 'abp-pool-save' ) ) {
				if ( row ) {
					var topic = row.querySelector( '.abp-pool-topic-edit' ).value.trim();
					fetch( poolRest + '/' + id, {
						method: 'PUT', headers: headers(),
						body: JSON.stringify( { topic: topic } ), credentials: 'same-origin'
					} ).then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
						.then( function ( d ) {
							poolMsgShow( d && d.ok ? '✅ 已保存' : ( '保存失败：' + ( ( d && d.error ) || ( d && ( d.message || d.detail ) ) || '未知错误' ) ), ! ( d && d.ok ) );
							if ( d && d.ok ) { loadPool(); }
						} )
						.catch( function ( e ) { poolMsgShow( '网络错误：' + ( e && e.message ? e.message : e ), true ); } );
				}
			}
			if ( el.classList.contains( 'abp-pool-del' ) ) {
				if ( ! window.confirm( '确定从池中删除该选题吗？' ) ) { return; }
				fetch( poolRest + '/' + id, {
					method: 'DELETE', headers: headers(), credentials: 'same-origin'
				} ).then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
					.then( function ( d ) {
						poolMsgShow( d && d.ok ? '✅ 已删除' : ( '删除失败：' + ( ( d && d.error ) || ( d && ( d.message || d.detail ) ) || '未知错误' ) ), ! ( d && d.ok ) );
						if ( d && d.ok ) { loadPool(); }
					} )
					.catch( function ( e ) { poolMsgShow( '网络错误：' + ( e && e.message ? e.message : e ), true ); } );
			}
			if ( el.classList.contains( 'abp-pool-plan' ) ) {
				fetch( poolRest + '/' + id + '/plan', {
					method: 'POST', headers: headers(), credentials: 'same-origin'
				} ).then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
					.then( function ( d ) {
						poolMsgShow( d && d.ok ? '✅ 已列入今日计划：' + ( ( d.task && d.task.task_id ) || '' ) + '（可在下方计划任务区立即完成/删除）' : ( '列入计划失败：' + ( ( d && d.error ) || ( d && ( d.message || d.detail ) ) || '未知错误' ) ), ! ( d && d.ok ) );
						if ( d && d.ok ) { loadPool(); loadPlan(); }
					} )
					.catch( function ( e ) { poolMsgShow( '网络错误：' + ( e && e.message ? e.message : e ), true ); } );
			}
			if ( el.classList.contains( 'abp-pool-run' ) ) {
				if ( ! window.confirm( '立即完成该备用题（列入计划并马上生成发布，消耗 AI 额度）？' ) ) { return; }
				fetch( poolRest + '/' + id + '/run', {
					method: 'POST', headers: headers(), credentials: 'same-origin'
				} ).then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
					.then( function ( d ) {
						poolMsgShow( d && d.ok ? '✅ 已列入计划并请求立即执行，生成引擎将优先处理（可在今日计划任务区查看进度）' : ( '执行失败：' + ( ( d && d.error ) || ( d && ( d.message || d.detail ) ) || '未知错误' ) ), ! ( d && d.ok ) );
						if ( d && d.ok ) { loadPool(); loadPlan(); }
					} )
					.catch( function ( e ) { poolMsgShow( '网络错误：' + ( e && e.message ? e.message : e ), true ); } );
			}
		} );
		/* ================= 今日计划任务（删除 / 立即完成 / 清空） ================= */
		var planBox = document.getElementById( 'abp-plan-container' );
		var planRefreshBtn = document.getElementById( 'abp-refresh-plan' );
		var planClearBtn = document.getElementById( 'abp-plan-clear' );
		var planRest = abp.tasksUrl || '';

		var planStatusLabel = function ( s ) {
			return { queued: '排队中', generating: '生成中', ready: '待发布', published: '已发布', failed: '失败', skipped: '已跳过' }[ s ] || s || '';
		};

		var renderPlan = function ( data ) {
			if ( ! data || ! data.tasks || ! data.tasks.length ) {
				planBox.innerHTML = '<p class="description">今日暂无计划任务。可到上方「备用选题池」点「列入计划」，或等每日 08:00 自动取题。</p>';
				return;
			}
			var html = '<table class="widefat striped"><thead><tr><th>栏目</th><th>选题</th><th>状态</th><th>发布时间</th><th>操作</th></tr></thead><tbody>';
			data.tasks.forEach( function ( t ) {
				var runnable = ( t.status === 'queued' || t.status === 'failed' );
				var deletable = ( t.status === 'queued' || t.status === 'skipped' );
				// 重写：published/ready/failed/skipped 均可重写（published → queued+run_now，发布端覆盖原文章）。
				var rewritable = ( t.status === 'published' || t.status === 'ready' || t.status === 'failed' || t.status === 'skipped' );
				html += '<tr data-task="' + esc( t.task_id ) + '">' +
					'<td>' + esc( columnName( t.column_name ) ) + '</td>' +
					'<td>' + esc( t.topic || '（未定题）' ) + '</td>' +
					'<td>' + esc( planStatusLabel( t.status ) ) + '</td>' +
					'<td>' + esc( ( t.publish_date || '' ).replace( 'T', ' ' ).slice( 0, 16 ) ) + '</td>' +
					'<td>' +
					( runnable ? '<button type="button" class="button button-small abp-plan-run" data-task="' + esc( t.task_id ) + '" title="立即生成并发布，不等定时">立即完成</button> ' : '' ) +
					( rewritable ? '<button type="button" class="button button-small abp-plan-rewrite" data-task="' + esc( t.task_id ) + '" title="重写并发布：重新生成内容，若已发布则覆盖原文章（含当日复盘）">重写</button> ' : '' ) +
					( deletable ? '<button type="button" class="button button-small abp-plan-del" data-task="' + esc( t.task_id ) + '">删除</button>' : '' ) +
					'</td></tr>';
			} );
			html += '</tbody></table>';
			planBox.innerHTML = html;
		};

		var loadPlan = function () {
			if ( ! planRest || ! planBox ) { return; }
			fetch( planRest, { headers: headers(), credentials: 'same-origin' } )
				.then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
				.then( function ( d ) {
					if ( d && d.ok === false ) { planBox.innerHTML = '<p class="description">加载失败：' + esc( d.error || ( d && ( d.message || d.detail ) ) || '未知错误' ) + '</p>'; }
					else { renderPlan( d ); }
				} )
				.catch( function ( e ) { planBox.innerHTML = '<p class="description">网络错误：' + esc( e && e.message ? e.message : e ) + '</p>'; } );
		};

		if ( planRefreshBtn && planBox ) {
			planRefreshBtn.addEventListener( 'click', loadPlan );
			loadPlan();
		}

		/* ---------- 任务重写（published→queued+run_now，覆盖原文章） ---------- */
		if ( planBox ) {
			planBox.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.abp-plan-rewrite' );
				if ( ! btn ) { return; }
				var taskId = btn.getAttribute( 'data-task' ) || '';
				if ( ! taskId ) { return; }
				if ( ! window.confirm( '确定重写任务 ' + taskId + ' 吗？\n将重新生成内容并发布；若该任务已发布过（含当日复盘），会覆盖原文章。' ) ) { return; }
				btn.disabled = true;
				var oldText = btn.textContent;
				btn.textContent = '重写中...';
				fetch( planRest + '/' + encodeURIComponent( taskId ) + '/rewrite', {
					method: 'POST', headers: headers(), credentials: 'same-origin'
				} )
					.then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
					.then( function ( d ) {
						if ( d && d.ok ) {
							alert( '✅ 已提交重写：' + taskId + '（生成引擎将重新生成并覆盖发布）' );
							loadPlan();
						} else {
							alert( '重写失败：' + ( ( d && d.error ) || ( d && ( d.message || d.detail ) ) || '未知错误' ) );
							btn.disabled = false;
							btn.textContent = oldText;
						}
					} )
					.catch( function ( e ) {
						alert( '网络错误：' + ( e && e.message ? e.message : e ) );
						btn.disabled = false;
						btn.textContent = oldText;
					} );
			} );
		}

		if ( planClearBtn ) {
			planClearBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( '确定清空今日计划任务（排队与跳过的）？已发布文章保留。' ) ) { return; }
				planClearBtn.disabled = true;
				fetch( planRest + '/clear', {
					method: 'POST', headers: headers(), credentials: 'same-origin'
				} ).then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
					.then( function ( d ) {
						poolMsgShow( d && d.ok ? '✅ 已清空 ' + ( d.deleted || 0 ) + ' 条计划任务' : ( '清空失败：' + ( ( d && d.error ) || ( d && ( d.message || d.detail ) ) || '未知错误' ) ), ! ( d && d.ok ) );
						if ( d && d.ok ) { loadPlan(); loadPool(); }
					} )
					.catch( function ( e ) { poolMsgShow( '网络错误：' + ( e && e.message ? e.message : e ), true ); } )
					.finally( function () { planClearBtn.disabled = false; } );
			} );
		}

		/* 计划任务行内操作（事件委托） */
		document.addEventListener( 'click', function ( ev ) {
			var el = ev.target;
			if ( ! el || ! el.classList ) { return; }
			var taskId = el.getAttribute ? el.getAttribute( 'data-task' ) : null;
			if ( ! taskId ) { return; }

			if ( el.classList.contains( 'abp-plan-del' ) ) {
				if ( ! window.confirm( '确定删除该计划任务吗？' ) ) { return; }
				fetch( planRest + '/' + encodeURIComponent( taskId ), {
					method: 'DELETE', headers: headers(), credentials: 'same-origin'
				} ).then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
					.then( function ( d ) {
						poolMsgShow( d && d.ok ? '✅ 已删除计划任务' : ( '删除失败：' + ( ( d && d.error ) || ( d && ( d.message || d.detail ) ) || '未知错误' ) ), ! ( d && d.ok ) );
						if ( d && d.ok ) { loadPlan(); }
					} )
					.catch( function ( e ) { poolMsgShow( '网络错误：' + ( e && e.message ? e.message : e ), true ); } );
			}

			if ( el.classList.contains( 'abp-plan-run' ) ) {
				if ( ! window.confirm( '立即完成该计划任务（生成并发布）？将调用 AI 模型消耗额度。' ) ) { return; }
				var runBtn = el;
				runBtn.disabled = true;
				runBtn.textContent = '生成中…';
				// 异步：接口秒回，后台生成，前端轮询任务状态（约 1-3 分钟）
				var pollTimer = null;
				var poll = function () {
					fetch( planRest + '/' + encodeURIComponent( taskId ), { headers: headers(), credentials: 'same-origin' } )
						.then( function ( r ) { return r.json().catch( function () { return { ok: false }; } ); } )
						.then( function ( d ) {
							var st = d && d.task ? d.task.status : '';
							if ( st === 'published' ) {
								poolMsgShow( '✅ 已发布，文章 ID ' + ( d.task.post_id || '' ) + '（' + ( d.task.error || '' ) + '）', false );
								runBtn.disabled = false; runBtn.textContent = '立即完成';
								loadPlan();
							} else if ( st === 'failed' || st === 'skipped' ) {
								poolMsgShow( '执行' + ( st === 'skipped' ? '跳过' : '失败' ) + '：' + ( d.task.error || '未知错误' ), true );
								runBtn.disabled = false; runBtn.textContent = '立即完成';
								loadPlan();
							} else {
								pollTimer = setTimeout( poll, 5000 );
							}
						} )
						.catch( function () { pollTimer = setTimeout( poll, 5000 ); } );
				};
				fetch( planRest + '/' + encodeURIComponent( taskId ) + '/run', {
					method: 'POST', headers: headers(), credentials: 'same-origin'
				} ).then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
					.then( function ( d ) {
						if ( d && d.ok && d.async ) {
							poolMsgShow( '⏳ 已开始生成（后台进行中），约 1-3 分钟完成，请稍候…', false );
							poll();
						} else if ( d && d.ok ) {
							poolMsgShow( d.post_id ? '✅ 已发布，文章 ID ' + d.post_id + ( d.permalink ? '：' + d.permalink : '' ) : ( ( d.note ) || '✅ 完成' ), false );
							runBtn.disabled = false; runBtn.textContent = '立即完成';
							loadPlan();
						} else {
							poolMsgShow( '执行失败：' + ( ( d && ( d.error || d.message || d.detail ) ) || '未知错误（请查看任务日志）' ), true );
							runBtn.disabled = false; runBtn.textContent = '立即完成';
						}
					} )
					.catch( function ( e ) {
						poolMsgShow( '网络错误：' + ( e && e.message ? e.message : e ), true );
						runBtn.disabled = false; runBtn.textContent = '立即完成';
					} );
			}
		} );
		/* ================= AI 工具箱（摘要 / 评论 / 话题：多选列表 + 批量） ================= */
		var tbTbody = document.getElementById( 'abp-toolbox-tbody' );
		var tbResult = document.getElementById( 'abp-toolbox-result' );
		var tbSelCount = document.getElementById( 'abp-toolbox-selcount' );
		var tbUrl = abp.toolboxUrl || '';
		var tbPosts = [];

		var tbShow = function ( text, isErr ) {
			if ( tbResult ) {
				tbResult.innerHTML = text;
				tbResult.style.color = isErr ? '#b32d2e' : '#2271b1';
			}
		};
		var esc = function ( s ) {
			return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
			} );
		};

		var tbLoadPosts = function () {
			if ( ! tbTbody ) { return; }
			tbTbody.innerHTML = '<tr><td colspan="7">加载中…</td></tr>';
			fetch( abp.ajaxUrl + '?action=abp_toolbox_posts&_ajax_nonce=' + encodeURIComponent( abp.logNonce ), { credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( d ) {
					if ( ! d || ! d.success ) { tbTbody.innerHTML = '<tr><td colspan="7">加载失败</td></tr>'; return; }
					tbPosts = d.data || [];
					tbRender();
				} )
				.catch( function () { tbTbody.innerHTML = '<tr><td colspan="7">加载失败</td></tr>'; } );
		};

		var tbRender = function () {
			if ( ! tbTbody ) { return; }
			if ( ! tbPosts.length ) { tbTbody.innerHTML = '<tr><td colspan="7">暂无文章</td></tr>'; return; }
			var html = '';
			tbPosts.forEach( function ( p ) {
				var sum = p.has_excerpt ? '<span class="abp-status-yes">✓ 有</span>' : '<span class="abp-status-no">— 无</span>';
				var cmt = p.comment_count > 0 ? p.comment_count + ' 条' : '—';
				var top = p.topics && p.topics.length ? '<span class="abp-status-yes">✓ ' + esc( p.topics.slice( 0, 2 ).join( '、' ) ) + '</span>' : '<span class="abp-status-no">— 无</span>';
				var cov = p.has_cover ? '<span class="abp-status-yes">✓ 有</span>' : '<span class="abp-status-no">— 无</span>';
				html += '<tr><td class="abp-col-check"><input type="checkbox" class="abp-tb-check" value="' + p.ID + '" /></td>' +
					'<td class="abp-tb-title"><a href="' + abp.homeUrl + '/index.php/archives/' + p.ID + '" target="_blank">' + esc( p.post_title ) + '</a></td>' +
					'<td class="abp-tb-date">' + esc( p.post_date ) + '</td>' +
					'<td class="abp-tb-status">' + sum + '</td>' +
					'<td class="abp-tb-status">' + cmt + '</td>' +
					'<td class="abp-tb-status">' + top + '</td>' +
					'<td class="abp-tb-status">' + cov + '</td></tr>';
			} );
			tbTbody.innerHTML = html;
			tbUpdateSel();
		};

		var tbSelected = function () {
			var ids = [];
			if ( tbTbody ) {
				tbTbody.querySelectorAll( '.abp-tb-check:checked' ).forEach( function ( cb ) { ids.push( parseInt( cb.value, 10 ) ); } );
			}
			return ids;
		};
		var tbUpdateSel = function () {
			if ( tbSelCount ) { tbSelCount.textContent = tbSelected().length; }
		};

		// 全选（两个全选框同步）
		var tbSetAll = function ( checked ) {
			if ( tbTbody ) { tbTbody.querySelectorAll( '.abp-tb-check' ).forEach( function ( cb ) { cb.checked = checked; } ); }
			var a1 = document.getElementById( 'abp-toolbox-all' );
			var a2 = document.getElementById( 'abp-toolbox-all2' );
			if ( a1 ) { a1.checked = checked; }
			if ( a2 ) { a2.checked = checked; }
			tbUpdateSel();
		};
		document.getElementById( 'abp-toolbox-all' ) && document.getElementById( 'abp-toolbox-all' ).addEventListener( 'change', function () { tbSetAll( this.checked ); } );
		document.getElementById( 'abp-toolbox-all2' ) && document.getElementById( 'abp-toolbox-all2' ).addEventListener( 'change', function () { tbSetAll( this.checked ); } );
		document.getElementById( 'abp-toolbox-refresh' ) && document.getElementById( 'abp-toolbox-refresh' ).addEventListener( 'click', tbLoadPosts );
		tbTbody && tbTbody.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.classList.contains( 'abp-tb-check' ) ) { tbUpdateSel(); }
		} );

		// 全局错误捕获（调试可见性：任何 JS 异常都弹窗）
		window.addEventListener( 'error', function ( e ) {
			alert( '页面脚本错误：' + ( e.message || '未知' ) );
		} );

		// 批量执行：逐篇串行调用（避免单请求超时），实时进度；任何异常/失败都弹窗提示
		var tbBatch = function ( action, extra, btn ) {
			try {
				var ids = tbSelected();
				if ( ! ids.length ) { tbShow( '请先勾选文章（可多选）', true ); alert( '请先勾选文章' ); return; }
				if ( btn ) { btn.disabled = true; }
				var okN = 0, failN = 0, cur = 0;
				var next = function () {
					if ( cur >= ids.length ) {
						var msg = '批量' + ( 'summary' === action ? '摘要' : 'comments' === action ? '评论' : 'ai-cover' === action ? 'AI 配图' : 'cover' === action ? '配图' : '话题' ) + '完成：成功 ' + okN + ' 篇，失败 ' + failN + ' 篇';
						tbShow( msg, failN > 0 && 0 === okN );
						alert( msg );
						if ( btn ) { btn.disabled = false; }
						tbLoadPosts();
						return;
					}
					var pid = ids[ cur ];
					cur++;
					tbShow( '处理中 ' + cur + '/' + ids.length + '（文章 ' + pid + '）…', false );
					var body = Object.assign( { post_id: pid }, extra || {} );
					var ctrl = new AbortController();
					var timer = setTimeout( function () { ctrl.abort(); }, 90000 );
					fetch( tbUrl + '/' + action, {
						method: 'POST', headers: headers(), signal: ctrl.signal,
						body: JSON.stringify( body ), credentials: 'same-origin'
					} ).then( function ( r ) { return r.json().catch( function () { return { ok: false, error: '响应解析失败 HTTP ' + r.status }; } ); } )
						.then( function ( d ) {
							clearTimeout( timer );
							if ( d && d.ok ) { okN++; } else { failN++; }
							next();
						} )
						.catch( function ( e2 ) {
							clearTimeout( timer );
							alert( '请求失败（文章 ' + pid + '）：' + ( e2 && e2.message ? e2.message : e2 ) );
							failN++;
							next();
						} );
				};
				next();
			} catch ( e3 ) {
				alert( '批量处理出错：' + ( e3 && e3.message ? e3.message : e3 ) );
				if ( btn ) { btn.disabled = false; }
			}
		};

		document.getElementById( 'abp-toolbox-summary' ) && document.getElementById( 'abp-toolbox-summary' ).addEventListener( 'click', function ( e ) {
			tbBatch( 'summary', {}, e.target );
		} );
		document.getElementById( 'abp-toolbox-comments' ) && document.getElementById( 'abp-toolbox-comments' ).addEventListener( 'click', function ( e ) {
			tbBatch( 'comments', {
				count: parseInt( document.getElementById( 'abp-toolbox-count' ).value || '5', 10 ),
				status: document.getElementById( 'abp-toolbox-cstatus' ).value
			}, e.target );
		} );
		document.getElementById( 'abp-toolbox-topics' ) && document.getElementById( 'abp-toolbox-topics' ).addEventListener( 'click', function ( e ) {
			tbBatch( 'topics', {}, e.target );
		} );
		document.getElementById( 'abp-toolbox-cover' ) && document.getElementById( 'abp-toolbox-cover' ).addEventListener( 'click', function ( e ) {
			tbBatch( 'ai-cover', {}, e.target );
		} );

		tbLoadPosts();
	} );
} )();

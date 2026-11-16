<?php
/**
 * ELN_Settings_Render_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Settings_Render_Trait {


	public static function render_template_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.', '', array( 'response' => 403 ) );
		}

		self::enqueue_template_assets();

		$kind    = self::current_kind();
		$is_auto = $kind === 'autoreply';

		// 自动确认按浏览器语言分语言组：页面一次只编辑一组（索引 0 = 默认兜底组）
		$slots      = $is_auto ? self::reply_groups() : array();
		$slot       = $is_auto ? self::current_slot( $slots ) : 0;
		$slot_group = $is_auto ? $slots[ $slot ] : array();
		$enabled    = $is_auto ? (string) ( self::tpl( 'autoreply' )['enabled'] ?? '0' ) : '0';

		// 通知只有一份模板；自动确认取「当前语言组」这一份
		$tpl = $is_auto ? $slot_group : self::tpl( 'notify' );
		// 代码框显示的永远是这个模板真正生效的源码：没保存过 tpl() 已注入默认，
		// 保存过就原样显示（清空过就是空的，不再偷偷塞回默认模板）
		$code = (string) $tpl['html'];

		$base_args = array( 'kind' => $kind );
		if ( $is_auto ) {
			$base_args['slot'] = $slot;
		}
		// 测试发信 / 恢复默认都带上 kind（与 slot），nonce 也按 kind 分，免得在 A 标签页误伤 B 的配置
		$test  = wp_nonce_url(
			add_query_arg( $base_args, admin_url( 'admin-post.php?action=eln_test_mail' ) ),
			'eln_test_mail_' . $kind
		);
		$reset = wp_nonce_url(
			add_query_arg( $base_args, admin_url( 'admin-post.php?action=eln_reset_template' ) ),
			'eln_reset_template_' . $kind
		);
		$name  = self::option_name( $kind );
		$group = $is_auto ? 'eln_autoreply_group' : 'eln_template_group';

        // phpcs:disable WordPress.Security.NonceVerification
		$notice = '';
		if ( isset( $_GET['eln_test'] ) ) {
			$ok     = $_GET['eln_test'] === '1';
			$notice = '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>'
					. esc_html( self::t( $ok ? 'tpl_test_ok' : 'tpl_test_fail' ) ) . '</p></div>';
		} elseif ( isset( $_GET['eln_reset'] ) ) {
			$notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html( self::t( 'tpl_reset_done' ) ) . '</p></div>';
		} elseif ( isset( $_GET['eln_reply_reset'] ) ) {
			$notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html( self::t( 'tpl_reset_done' ) ) . '</p></div>';
		} elseif ( isset( $_GET['eln_lang_added'] ) ) {
			$notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html( self::t( 'tpl_lang_added' ) ) . '</p></div>';
		} elseif ( isset( $_GET['eln_lang_deleted'] ) ) {
			$notice = '<div class="notice notice-success is-dismissible"><p>' . esc_html( self::t( 'tpl_lang_deleted' ) ) . '</p></div>';
		} elseif ( isset( $_GET['eln_lang_exists'] ) ) {
			$notice = '<div class="notice notice-warning is-dismissible"><p>' . esc_html( self::t( 'tpl_lang_exists' ) ) . '</p></div>';
		}
        // phpcs:enable

		// 可改的字段标签：字段集与邮件预览共用 label_fields()，两处永远对得上；
		// 传当前模板的 labels 进去，列表顺序 = 用户拖出来的行序
		$label_meta        = self::label_fields( (array) ( $tpl['labels'] ?? array() ) );
		$label_fields      = (array) $label_meta['fields'];
		$labels_are_sample = (bool) $label_meta['sample'];

		// 占位符分组 + 悬浮说明（占位符只输出值，标签由源码自己写）
		$placeholder_groups = array(
			array( '{{rows}}' ),
			array( '{{name}}', '{{email}}', '{{no}}', '{{source}}', '{{form_type}}' ),
			array( '{{time}}', '{{time_local}}', '{{ip}}', '{{lang}}', '{{time_zone}}' ),
		);
		// 需要前端配合才有值的占位符：chip 上标 *，下面给一条图例
		$placeholder_needs = array(
			'{{rows}}'       => true,
			'{{name}}'       => true,
			'{{email}}'      => true,
			'{{source}}'     => true,
			'{{form_type}}'  => true,
			'{{time_local}}' => true,
			'{{lang}}'       => true,
			'{{time_zone}}'  => true,
		);
		$placeholder_hints = array(
			'{{rows}}'       => '字段表格：姓名、邮箱、内容…（表头用字段标签，没配就用表单自己的措辞）',
			'{{name}}'       => '访客姓名',
			'{{email}}'      => '访客邮箱',
			'{{no}}'         => '询盘编号，如 20261105001',
			'{{source}}'     => '来源页面的英文标题，如 Contact Us',
			'{{form_type}}'  => '页面表单 / 弹窗表单',
			'{{time}}'       => '提交时间（站点时区，服务端收到请求的时刻）',
			'{{time_local}}' => '提交时间（换算到访客时区）',
			'{{ip}}'         => '提交者 IP（服务端识别）',
			'{{lang}}'       => '浏览器语言，如 zh-CN',
			'{{time_zone}}'  => '访客时区，如 Asia/Shanghai',
		);
		$legend            = sprintf(
			'%s <code>browserLanguage</code> → {{lang}}，<code>timezone</code> → {{time_zone}} / {{time_local}}，<code>pageTitle</code> / <code>pageUrl</code> → {{source}}。%s',
			self::t( 'tpl_legend_head' ),
			self::t( 'tpl_legend_tail' )
		);
		?>

		<div class="wrap">
			<h1><?php echo esc_html( self::t( 'tpl_menu' ) ); ?></h1>
			<?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<nav class="nav-tab-wrapper" style="margin:0 0 6px;">
				<?php
				$tabs = array(
					'notify'    => self::t( 'tpl_tab_notify' ),
					'autoreply' => self::t( 'tpl_tab_reply' ),
					'reply'     => self::t( 'reply_menu' ),
				);
				foreach ( $tabs as $tab_kind => $tab_label ) :
					?>
					<a href="<?php echo esc_url( self::template_tab_url( $tab_kind ) ); ?>"
						class="nav-tab<?php echo $tab_kind === $kind ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $tab_label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<p class="eln-tpl-lede"><?php echo esc_html( self::t( 'tpl_intro' ) ); ?></p>

			<?php if ( $kind === 'reply' ) : ?>
				<?php
				// 「邮件回复」标签：mailto 的 to/cc/bcc/主题/正文骨架；保存空白就是空白，「恢复默认」才回内置模板
				$reply_reset = wp_nonce_url( admin_url( 'admin-post.php?action=eln_reply_reset' ), 'eln_reply_reset' );
				?>
				<div class="eln-tpl-card">
					<div class="eln-tpl-card__head">
						<div>
							<h2><?php echo esc_html( self::t( 'reply_menu' ) ); ?></h2>
							<p class="description"><?php echo esc_html( self::t( 'reply_card_hint' ) ); ?></p>
						</div>
						<div class="eln-tpl-card__actions">
							<a class="button" href="<?php echo esc_url( $reply_reset ); ?>"
								title="<?php echo esc_attr( self::t( 'reply_reset' ) ); ?>"
								onclick="return confirm('<?php echo esc_js( self::t( 'reply_reset' ) ); ?>?');"><?php echo esc_html( self::t( 'reply_reset' ) ); ?></a>
						</div>
					</div>
					<form method="post" action="options.php" class="eln-tpl-card__body">
						<?php settings_fields( 'eln_reply_group' ); ?>
						<?php $reply_tpl = self::reply_tpl(); ?>
						<div class="eln-tpl-row">
							<div class="eln-tpl-field eln-tpl-field--grow">
								<label for="eln-reply-to"><?php echo esc_html( self::t( 'reply_to' ) ); ?></label>
								<input type="text" name="<?php echo esc_attr( self::OPT_REPLY ); ?>[to]" id="eln-reply-to"
										class="large-text" value="<?php echo esc_attr( $reply_tpl['to'] ); ?>" />
							</div>
						</div>
						<div class="eln-tpl-row">
							<div class="eln-tpl-field eln-tpl-field--grow">
								<label for="eln-reply-cc"><?php echo esc_html( self::t( 'reply_cc' ) ); ?></label>
								<input type="text" name="<?php echo esc_attr( self::OPT_REPLY ); ?>[cc]" id="eln-reply-cc"
										class="large-text" value="<?php echo esc_attr( $reply_tpl['cc'] ); ?>" />
							</div>
							<div class="eln-tpl-field eln-tpl-field--grow">
								<label for="eln-reply-bcc"><?php echo esc_html( self::t( 'reply_bcc' ) ); ?></label>
								<input type="text" name="<?php echo esc_attr( self::OPT_REPLY ); ?>[bcc]" id="eln-reply-bcc"
										class="large-text" value="<?php echo esc_attr( $reply_tpl['bcc'] ); ?>" />
							</div>
						</div>
						<div class="eln-tpl-row">
							<div class="eln-tpl-field eln-tpl-field--grow">
								<label for="eln-reply-subject"><?php echo esc_html( self::t( 'reply_subject' ) ); ?></label>
								<input type="text" name="<?php echo esc_attr( self::OPT_REPLY ); ?>[subject]" id="eln-reply-subject"
										class="large-text" value="<?php echo esc_attr( $reply_tpl['subject'] ); ?>" />
							</div>
						</div>
						<div class="eln-tpl-field">
							<label for="eln-reply-body"><?php echo esc_html( self::t( 'reply_body' ) ); ?></label>
							<textarea class="large-text code" rows="10" id="eln-reply-body"
										name="<?php echo esc_attr( self::OPT_REPLY ); ?>[body]"><?php echo esc_textarea( $reply_tpl['body'] ); ?></textarea>
						</div>
						<p class="description"><?php echo esc_html( self::t( 'reply_hint' ) ); ?></p>
						<p class="eln-tpl-save"><?php submit_button( null, 'primary', 'submit', false ); ?></p>
					</form>
				</div>
			<?php else : ?>
			<div class="eln-tpl-grid">
				<div class="eln-tpl-card">
					<div class="eln-tpl-card__head">
						<h2><?php echo esc_html( $is_auto ? self::t( 'tpl_tab_reply' ) : self::t( 'tpl_tab_notify' ) ); ?></h2>
						<div class="eln-tpl-card__actions">
							<a class="button" href="<?php echo esc_url( $test ); ?>"
								title="<?php echo esc_attr( self::t( 'tpl_test_hint' ) ); ?>"><?php echo esc_html( self::t( 'tpl_test' ) ); ?></a>
							<a class="button" href="<?php echo esc_url( $reset ); ?>"
								title="<?php echo esc_attr( self::t( 'tpl_reset' ) ); ?>"
								onclick="return confirm('<?php echo esc_js( self::t( 'tpl_reset' ) ); ?>?');"><?php echo esc_html( self::t( 'tpl_reset' ) ); ?></a>
						</div>
					</div>

					<?php if ( $is_auto ) : ?>
						<div class="eln-lang-bar">
							<span class="eln-lang-bar__label"><?php echo esc_html( self::t( 'tpl_lang_bar' ) ); ?></span>
							<ul class="eln-lang-list">
								<?php
								foreach ( $slots as $i => $item ) :
									// 默认组不可删；其它组的删除入口就放在各自的标签上
									$del_url = $i === 0 ? '' : wp_nonce_url(
										add_query_arg(
											array(
												'action' => 'eln_reply_dellang',
												'slot'   => $i,
											),
											admin_url( 'admin-post.php' )
										),
										'eln_reply_lang_' . $i
									);
									?>
									<li class="eln-lang-item<?php echo $del_url !== '' ? ' has-del' : ''; ?>">
										<a class="eln-lang-tab<?php echo $i === $slot ? ' is-active' : ''; ?>"
											href="<?php echo esc_url( self::reply_slot_url( $i ) ); ?>"
											title="<?php echo esc_attr( $item['lang'] === '' ? self::t( 'tpl_lang_default_hint' ) : self::lang_label( (string) $item['lang'] ) ); ?>">
											<?php
											echo esc_html( self::lang_label( (string) $item['lang'] ) );
											?>
											</a>
										<?php if ( $del_url !== '' ) : ?>
											<a class="eln-lang-del" href="<?php echo esc_url( $del_url ); ?>"
												title="<?php echo esc_attr( self::t( 'tpl_lang_delete' ) ); ?>"
												onclick="return confirm('<?php echo esc_js( self::t( 'tpl_lang_delete_confirm' ) ); ?>');">×</a>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eln-lang-add">
								<input type="hidden" name="action" value="eln_reply_addlang" />
								<?php // 独立字段名，避免和下面 settings_fields 的 _wpnonce 撞 id ?>
								<?php wp_nonce_field( 'eln_reply_lang', '_wpnonce_lang' ); ?>
								<select name="newlang" aria-label="<?php echo esc_attr( self::t( 'tpl_lang_code' ) ); ?>">
									<option value=""><?php echo esc_html( self::t( 'tpl_lang_add' ) ); ?>…</option>
									<?php
									$used_langs = array_map(
										static function ( $g ) {
											return (string) $g['lang'];
										},
										$slots
									);
									foreach ( self::lang_options() as $opt_code => $opt_label ) :
										if ( in_array( $opt_code, $used_langs, true ) ) {
											continue;
										}
										?>
										<option value="<?php echo esc_attr( $opt_code ); ?>"><?php echo esc_html( $opt_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="button button-small"><?php echo esc_html( self::t( 'tpl_lang_add' ) ); ?></button>
							</form>
							<p class="eln-lang-hint"><?php echo esc_html( self::t( 'tpl_lang_hint' ) ); ?></p>
						</div>
					<?php endif; ?>

					<form method="post" action="options.php" id="eln-tpl-form" class="eln-tpl-card__body">
						<?php settings_fields( $group ); ?>

						<?php if ( $is_auto ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>[cur]" value="<?php echo (int) $slot; ?>" />
							<div class="eln-tpl-field--check">
								<label class="eln-tpl-check">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1"
											<?php checked( '1', $enabled ); ?> />
									<?php echo esc_html( self::t( 'tpl_reply_on' ) ); ?>
								</label>
								<p class="description"><?php echo esc_html( self::t( 'tpl_reply_on_hint' ) ); ?></p>
							</div>
						<?php endif; ?>

						<div class="eln-tpl-row">
							<?php if ( $is_auto ) : ?>
								<div class="eln-tpl-field eln-lang-code">
									<label for="eln-lang"><?php echo esc_html( self::t( 'tpl_lang_code' ) ); ?></label>
									<?php if ( $slot === 0 ) : ?>
										<input type="text" id="eln-lang" value="<?php echo esc_attr( self::t( 'tpl_lang_default' ) ); ?>" disabled />
									<?php else : ?>
										<?php
										$lang_options = self::lang_options();
										$current_lang = (string) $slot_group['lang'];
										// 兼容手改过的旧值：不在候选表里也要能看见、能保存
										if ( $current_lang !== '' && ! isset( $lang_options[ $current_lang ] ) ) {
											$lang_options = array( $current_lang => self::lang_label( $current_lang ) ) + $lang_options;
										}
										?>
										<select name="<?php echo esc_attr( $name ); ?>[lang]" id="eln-lang">
											<?php foreach ( $lang_options as $opt_code => $opt_label ) : ?>
												<option value="<?php echo esc_attr( $opt_code ); ?>" <?php selected( $current_lang, $opt_code ); ?>><?php echo esc_html( $opt_label ); ?></option>
											<?php endforeach; ?>
										</select>
									<?php endif; ?>
								</div>
							<?php endif; ?>
							<div class="eln-tpl-field eln-tpl-field--grow">
								<label for="eln-subject"><?php echo esc_html( self::t( 'tpl_subject' ) ); ?></label>
								<input name="<?php echo esc_attr( $name ); ?>[subject]" id="eln-subject" type="text"
										class="large-text" value="<?php echo esc_attr( (string) $tpl['subject'] ); ?>"
										placeholder="
										<?php
										echo esc_attr(
											$is_auto
											? self::t( 'email_subject_reply' )
											: sprintf( self::t( 'email_subject' ), '{{name}} <{{email}}>' )
										);
										?>
											" />
								<p class="description"><?php echo esc_html( self::t( 'tpl_subject_hint' ) ); ?></p>
							</div>
						</div>

						<details class="eln-tpl-details"<?php echo $label_fields ? '' : ' open'; ?>>
							<summary>
								<?php echo esc_html( self::t( 'tpl_labels' ) ); ?>
								<span class="eln-tpl-badge"><?php echo count( $label_fields ); ?></span>
							</summary>
							<p class="description"><?php echo esc_html( $is_auto ? self::t( 'tpl_reply_labels_hint' ) : self::t( 'tpl_labels_hint' ) ); ?></p>
							<?php if ( $labels_are_sample ) : ?>
								<p class="description"><em><?php echo esc_html( self::t( 'tpl_labels_sample' ) ); ?></em></p>
							<?php endif; ?>
							<?php if ( $label_fields ) : ?>
								<div class="eln-tpl-labels" data-sortable-labels>
									<?php foreach ( $label_fields as $field_key => $default_label ) : ?>
										<div class="eln-tpl-label-row">
											<span class="eln-sort" title="<?php echo esc_attr( self::t( 'tpl_labels_sort' ) ); ?>" aria-hidden="true">⠿</span>
											<span class="eln-tpl-label-key"><?php echo esc_html( $field_key ); ?></span>
											<input type="text"
													name="<?php echo esc_attr( $name ); ?>[labels][<?php echo esc_attr( $field_key ); ?>]"
													value="<?php echo esc_attr( (string) ( $tpl['labels'][ $field_key ] ?? '' ) ); ?>"
													placeholder="<?php echo esc_attr( $default_label ); ?>" />
										</div>
									<?php endforeach; ?>
								</div>
								<p class="description eln-labels-hint"><?php echo esc_html( self::t( 'tpl_labels_order_hint' ) ); ?></p>
							<?php endif; ?>
						</details>

						<h2 class="eln-tpl-section-title"><label for="eln-html"><?php echo esc_html( self::t( 'tpl_html' ) ); ?></label></h2>
						<div class="eln-tpl-toolbar">
							<div class="eln-tpl-toolbar__row">
								<button type="button" class="button" id="eln-insert-media"><?php echo esc_html( self::t( 'tpl_media' ) ); ?></button>
								<button type="button" class="button" id="eln-format-code"><?php echo esc_html( self::t( 'tpl_format' ) ); ?></button>
								<span class="description"><?php echo esc_html( self::t( 'tpl_media_hint' ) ); ?></span>
							</div>
							<div class="eln-tpl-toolbar__row">
								<span class="eln-tpl-toolbar__label"><?php echo esc_html( self::t( 'tpl_placeholders' ) ); ?></span>
								<div class="eln-tpl-chips">
									<?php foreach ( $placeholder_groups as $group ) : ?>
										<?php foreach ( $group as $ph ) : ?>
											<button type="button" class="button button-small eln-tpl-chip"
													data-insert-ph="<?php echo esc_attr( $ph ); ?>"
													title="<?php echo esc_attr( $ph . ' — ' . ( $placeholder_hints[ $ph ] ?? '' ) ); ?>"><?php echo esc_html( $ph ); ?>
													<?php
													if ( isset( $placeholder_needs[ $ph ] ) ) :
														?>
														<span class="eln-req">*</span><?php endif; ?></button>
										<?php endforeach; ?>
										<span class="eln-tpl-chips__sep" aria-hidden="true"></span>
									<?php endforeach; ?>
								</div>
							</div>
							<p class="eln-tpl-legend"><?php echo wp_kses_post( $legend ); ?></p>
						</div>
						<textarea name="<?php echo esc_attr( $name ); ?>[html]" id="eln-html" rows="22" class="large-text code"><?php echo esc_textarea( $code ); ?></textarea>
						<p class="description"><?php echo esc_html( self::t( 'tpl_html_hint' ) ); ?></p>
						<p class="eln-tpl-save"><?php submit_button( null, 'primary', 'submit', false ); ?></p>
					</form>
				</div>

				<div class="eln-tpl-card eln-tpl-preview">
					<div class="eln-tpl-card__head">
						<h2><?php echo esc_html( self::t( 'tpl_preview' ) ); ?></h2>
						<span class="description"><?php echo esc_html( self::t( 'tpl_preview_hint' ) ); ?></span>
					</div>
					<div class="eln-preview-body">
						<iframe id="eln-preview" title="<?php echo esc_attr( self::t( 'tpl_preview' ) ); ?>"></iframe>
					</div>
					<p class="eln-preview-note"><?php echo esc_html( self::t( 'tpl_sample_note' ) ); ?>: Jane Doe &lt;jane.doe@example.com&gt;</p>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

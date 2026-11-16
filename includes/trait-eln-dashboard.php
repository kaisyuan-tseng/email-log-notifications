<?php
/**
 * ELN_Dashboard_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Dashboard_Trait {


	/** 仪表盘「最近询盘」小挂件：只有能看询盘列表的用户才显示 */
	public static function register_dashboard_widget(): void {
		if ( ! current_user_can( Email_Log_Notifications::CAP_VIEW ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'eln_recent',
			self::t( 'dash_title' ),
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	public static function render_dashboard_widget(): void {
		self::enqueue_admin_assets();
		$limit = max( 1, (int) apply_filters( 'eln_dashboard_limit', 5 ) );
		$posts = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$counts = wp_count_posts( self::POST_TYPE );
		$total  = (int) ( $counts->publish ?? 0 );
		$today  = (int) ( new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'after'     => 'today midnight',
						'inclusive' => true,
					),
				),
			)
		) )->found_posts;

		$all_url = admin_url( 'edit.php?post_type=' . self::POST_TYPE );
		?>
		<div class="si-dash">
			<p class="si-dash__meta">
				<span><?php echo esc_html( sprintf( self::t( 'dash_today' ), $today ) ); ?></span>
				<span aria-hidden="true">·</span>
				<span><?php echo esc_html( sprintf( self::t( 'dash_total' ), $total ) ); ?></span>
			</p>
			<?php if ( ! $posts ) : ?>
				<p class="si-dash__empty"><?php echo esc_html( self::t( 'dash_empty' ) ); ?></p>
			<?php else : ?>
				<ul class="si-dash__list">
					<?php
					foreach ( $posts as $item ) :
						$detail = add_query_arg(
							array(
								'post_type' => self::POST_TYPE,
								'eln_view'  => $item->ID,
							),
							admin_url( 'edit.php' )
						);
						$name   = (string) get_post_meta( $item->ID, 'eln_inquiry_name', true );
						$name   = $name !== '' ? $name : $item->post_title;
						$email  = (string) get_post_meta( $item->ID, 'eln_inquiry_email', true );
						$source = self::record_source_label( $item->ID );
						$sub    = implode( ' · ', array_filter( array( $email, $source ) ) );
						$when   = self::dash_date_label( (int) get_post_time( 'U', true, $item ) );
						// 发信状态：两枚小圆点（询盘通知 / 自动确认），口径与列表一致
						$n_state    = (string) get_post_meta( $item->ID, 'eln_inquiry_mail_sent', true );
						$r_state    = (string) get_post_meta( $item->ID, 'eln_inquiry_autoreply_sent', true );
						$dot_cls    = static function ( string $s ): string {
							return $s === 'yes' ? 'is-ok' : ( $s === 'no' ? 'is-fail' : 'is-none' );
						};
						$word2      = static function ( string $s ): string {
							return $s === 'yes' ? self::t( 'status_yes' ) : ( $s === 'no' ? self::t( 'status_no' ) : self::t( 'status_none' ) );
						};
						$mail_title = self::t( 'label_notify' ) . '：' . $word2( $n_state ) . "\n"
									. self::t( 'label_reply' ) . '：' . $word2( $r_state );
						?>
						<li class="si-dash__item">
							<span class="si-dash__date"><?php echo esc_html( $when ); ?></span>
							<span class="si-dash__main">
								<a class="si-dash__name" href="<?php echo esc_url( $detail ); ?>"><?php echo esc_html( $name ); ?></a>
								<?php if ( $sub !== '' ) : ?>
									<span class="si-dash__sub"><?php echo esc_html( $sub ); ?></span>
								<?php endif; ?>
							</span>
							<span class="si-dash__mail" title="<?php echo esc_attr( $mail_title ); ?>">
								<span class="si-dash__status"><i class="si-dash__dot <?php echo esc_attr( $dot_cls( $n_state ) ); ?>"></i><?php echo esc_html( self::t( 'label_notify' ) ); ?></span>
								<span class="si-dash__status"><i class="si-dash__dot <?php echo esc_attr( $dot_cls( $r_state ) ); ?>"></i><?php echo esc_html( self::t( 'label_reply' ) ); ?></span>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="si-dash__foot">
				<a href="<?php echo esc_url( $all_url ); ?>"><?php echo esc_html( self::t( 'dash_all' ) ); ?>
					<span class="count">(<?php echo (int) $total; ?>)</span></a>
			</p>
		</div>
		<?php
	}

	/** 挂件里的时间：今天/昨天带时刻，同年只到日，跨年带年（口径同核心「最近发布」） */
	private static function dash_date_label( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '—';
		}
		$day   = wp_date( 'Y-m-d', $timestamp );
		$today = current_time( 'Y-m-d' );
		$ytd   = current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );

		if ( $day === $today ) {
			return sprintf( self::t( 'dash_today_at' ), wp_date( 'H:i', $timestamp ) );
		}
		if ( $day === $ytd ) {
			return sprintf( self::t( 'dash_yesterday_at' ), wp_date( 'H:i', $timestamp ) );
		}
		if ( wp_date( 'Y', $timestamp ) === current_time( 'Y' ) ) {
			return wp_date( 'n/j H:i', $timestamp );
		}
		return wp_date( 'Y/n/j', $timestamp );
	}
}

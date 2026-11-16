<?php
/**
 * ELN_Upload_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Upload_Trait {


	/** 校验并把上传文件挪进本次提交的暂存目录，返回 array( name, tmp_path, size ) */
	private static function stage_attachments( array $files ): array {
		$staged = array();
		if ( empty( $files ) ) {
			return $staged;
		}

		$stage_dir = self::stage_dir();
		if ( ! wp_mkdir_p( $stage_dir ) ) {
			throw new RuntimeException( 'Could not create temp directory.' );
		}

		$max_files = (int) apply_filters( 'eln_max_files', 20 );

		foreach ( self::FILE_GROUPS as $group => $spec ) {
			if ( empty( $files[ $group ] ) ) {
				continue;
			}
			// 前端用 "group[]" 提交；单文件时 PHP 给的是标量，统一成数组
			$names  = (array) $files[ $group ]['name'];
			$tmp    = (array) $files[ $group ]['tmp_name'];
			$sizes  = (array) $files[ $group ]['size'];
			$errors = (array) $files[ $group ]['error'];

			foreach ( $names as $i => $original ) {
				$error_code = (int) ( $errors[ $i ] ?? UPLOAD_ERR_NO_FILE );
				if ( $error_code === UPLOAD_ERR_NO_FILE ) {
					continue;
				}
				if ( $error_code !== UPLOAD_ERR_OK ) {
					throw new RuntimeException( 'Upload failed (code ' . esc_html( (string) $error_code ) . ').' );
				}
				if ( count( $staged ) >= $max_files ) {
					throw new RuntimeException( 'Too many files in one submission.' );
				}
				$tmp_path = (string) ( $tmp[ $i ] ?? '' );
				$ext      = strtolower( pathinfo( (string) $original, PATHINFO_EXTENSION ) );
				$checked  = wp_check_filetype_and_ext( $tmp_path, (string) $original, $spec['ext'] );

				if ( empty( $checked['ext'] ) || ! in_array( strtolower( (string) $checked['ext'] ), $spec['ext'], true ) ) {
					throw new RuntimeException( sprintf( 'File type .%s is not allowed.', esc_html( $ext ) ) );
				}
				if ( in_array( strtolower( (string) $checked['type'] ), array( 'text/html', 'application/x-php', 'application/php' ), true ) ) {
					throw new RuntimeException( sprintf( 'File type .%s is not allowed.', esc_html( $ext ) ) );
				}

				$size = @filesize( $tmp_path );
				if ( false === $size || $size <= 0 ) {
					throw new RuntimeException( sprintf( '%s is empty.', esc_html( (string) $original ) ) );
				}
				if ( (int) $sizes[ $i ] !== (int) $size ) {
					throw new RuntimeException( sprintf( '%s has an invalid declared size.', esc_html( (string) $original ) ) );
				}
				if ( $size > $spec['max_size'] ) {
					throw new RuntimeException( sprintf( '%s exceeds the %dMB limit.', esc_html( (string) $original ), esc_html( (string) ( $spec['max_size'] / 1048576 ) ) ) );
				}

				$safe_name = wp_unique_filename( $stage_dir, sanitize_file_name( (string) $original ) );
				$dest      = $stage_dir . '/' . $safe_name;
				if ( ! move_uploaded_file( $tmp_path, $dest ) ) {
					throw new RuntimeException( 'Could not store uploaded file.' );
				}
				$staged[] = array(
					'name'     => $safe_name,
					'tmp_path' => self::norm( $dest ),
					'size'     => (int) $size,
				);
			}
		}
		return $staged;
	}

	/** 本次提交的暂存目录（进程内固定，方便统一清理） */
	private static function stage_dir(): string {
		static $dir = null;
		if ( $dir === null ) {
			$dir = self::norm( get_temp_dir() ) . 'site-inq-' . uniqid();
		}
		return $dir;
	}

	/** 附件持久化：uploads/inquiries/{Ym}/{文章ID-随机}/，返回留档后的文件数组 */
	private static function persist_attachments( array $staged, int $record_id ): array {
		if ( ! $staged ) {
			return array();
		}
		$dir = self::record_dir( $record_id );
		if ( ! wp_mkdir_p( $dir ) ) {
			return $staged; // 挪不动就退回暂存路径，至少邮件还能带附件
		}
		$stored = array();
		foreach ( $staged as $file ) {
			$dest = $dir . '/' . $file['name'];
			if ( ! @rename( $file['tmp_path'], $dest ) ) {
				$dest = $file['tmp_path']; // 失败退回暂存路径
			}
			// 相对 Web 路径：主站 80 与 WP 8081 两个域都能直接打开
			$web_path = str_starts_with( self::norm( $dest ), self::storage_base() )
				? self::storage_web_base() . substr( self::norm( $dest ), strlen( self::storage_base() ) )
				: '';
			$stored[] = array(
				'name' => $file['name'],
				'path' => self::norm( $dest ),
				'url'  => $web_path,
				'size' => $file['size'],
			);
		}
		return $stored;
	}

	/** 某条询盘的附件目录（随机后缀，避免可猜测的直链） */
	private static function record_dir( int $record_id ): string {
		static $suffixes = array();
		if ( ! isset( $suffixes[ $record_id ] ) ) {
			$suffixes[ $record_id ] = uniqid();
		}
		return self::storage_base() . gmdate( 'Ym' ) . '/' . $record_id . '-' . $suffixes[ $record_id ];
	}

	/** 删除询盘记录时，连同附件目录一起清理 */
	public static function purge_record_files( int $post_id ): void {
		if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
			return;
		}
		$files = (array) get_post_meta( $post_id, 'eln_inquiry_attachments', true );
		$dirs  = array();
		foreach ( $files as $file ) {
			$path = self::norm( (string) ( $file['path'] ?? '' ) );
			if ( $path !== '' && str_starts_with( $path, self::storage_base() ) ) {
				wp_delete_file( $path );
				$dirs[ dirname( $path ) ] = true;
			}
		}
		foreach ( array_keys( $dirs ) as $dir ) {
			if ( is_dir( $dir ) ) {
				@rmdir( $dir ); // 清空了才删得掉；残留留待人工
			}
		}
	}

	/**
	 * 清空本次提交的暂存目录。
	 *
	 * @param array $keep 仍被引用的文件路径（挪进留档目录失败时退回暂存路径的），不删.
	 */
	private static function cleanup_stage( array $keep = array() ): void {
		$dir = self::stage_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$keep = array_map( array( __CLASS__, 'norm' ), $keep );
		foreach ( glob( $dir . '/*' ) ?: array() as $f ) {
			if ( in_array( self::norm( $f ), $keep, true ) ) {
				continue;
			}
			wp_delete_file( $f );
		}
		@rmdir( $dir ); // 还有保留文件时删不掉，留着给下次或人工处理
	}

	/**
	 * 限流用的请求标识（与邮件里的 {{ip}} 分开：那边如实记录服务端看到的地址）。
	 *
	 *   1. 默认只取 REMOTE_ADDR；只有配置的可信代理才采信 X-Forwarded-For / X-Real-IP；
	 *   2. 回环或私网地址退化成服务端签发的 HttpOnly cookie（带 HMAC 签名），每个访客一个桶。
	 *
	 * 注意：cookie 是"区分访客"用的，不是身份凭证；丢弃 cookie 的客户端
	 * 会拿到新标识，所以要拦住无状态刷接口还得靠链路层拿到真实 IP。
	 */
}

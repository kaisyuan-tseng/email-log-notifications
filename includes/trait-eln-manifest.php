<?php
/**
 * ELN_Manifest_Trait — Email Log & Notifications.
 */

defined( 'ABSPATH' ) || exit;

trait ELN_Manifest_Trait {

	private static function frontend_field_manifest(): array {
		$manifest = apply_filters( 'eln_field_manifest', array() );
		if ( ! is_array( $manifest ) ) {
			return array();
		}
		$keys = array();
		foreach ( $manifest as $key ) {
			if ( is_string( $key ) && $key !== '' ) {
				$keys[] = $key;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * 可改的字段集合：字段名 => 默认标签（**顺序 = 后台排出来的行序**）。
	 *
	 * 字段名从哪来：前端清单（/inquiry-fields.json 的字段名与顺序）+ 历史询盘带来过的字段，
	 * 插件不预置任何字段。默认标签**只做字段名格式化**（outerDiameter → Outer Diameter），
	 * 不取前端任何语言的译文——要改说法就在编辑器里自己填，这才是与前端文案的解耦。
	 *
	 * @param array|null $preferred 当前模板保存的 labels，其键序决定列表顺序（用户拖过就是它）.
	 */
	private static function label_fields( ?array $preferred = null ): array {
		global $wpdb;

		$preferred = $preferred ?? (array) ( self::tpl( 'notify' )['labels'] ?? array() );
		$seen      = array();

		// fieldLabels 是「字段名 => 标签」的完整集合（含没填值的字段），
		// inquiry_fields 是旧版本只记非空字段的产物；两者都收，才能列全历史字段名。
		// 按 meta_id 倒序读：最新一封询盘的字段顺序（= 表单上的顺序）优先。
		$rows = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key IN ('eln_inquiry_field_labels', 'eln_inquiry_fields')
             ORDER BY meta_id DESC LIMIT 400"
		);
		foreach ( (array) $rows as $serialized ) {
			foreach ( (array) maybe_unserialize( $serialized ) as $key => $value ) {
				$field_key = is_string( $key ) ? $key : (string) $value; // labels 用键，fields 用值
				// 单位是值的后缀（quantityUnit → "500 PCS"），在 build_rows 里并进数值，
				// 不单独成行，所以也不该出现在标签表里
				if ( $field_key !== ''
					&& ! in_array( $field_key, self::META_FIELDS, true )
					&& ! str_ends_with( $field_key, 'Unit' )
				) {
					$seen[ $field_key ] = true;
				}
			}
		}
		// 后台手工加过标签、但历史数据里从没出现过的字段也要留住
		foreach ( array_keys( $preferred ) as $field_key ) {
			$seen[ $field_key ] = true;
		}

		// 前端声明的字段名清单（只取名字与顺序）：补齐表单可能出现、但还没有询盘提交过的字段
		$manifest = self::frontend_field_manifest();
		foreach ( $manifest as $field_key ) {
			$seen[ $field_key ] = true;
		}

		$sample = ! $seen; // 什么都没有 = 完全没有字段来源，界面给空态提示

		// 默认标签 = 字段名格式化（插件唯一的"翻译"手段），清单外的历史字段同理
		$defaults = array();
		foreach ( $manifest as $field_key ) {
			if ( isset( $seen[ $field_key ] ) ) {
				$defaults[ $field_key ] = self::readable_label( $field_key );
			}
		}
		foreach ( array_keys( $seen ) as $field_key ) {
			if ( ! isset( $defaults[ $field_key ] ) ) {
				$defaults[ $field_key ] = self::readable_label( $field_key );
			}
		}

		// 1) 用户排过的顺序优先（后台拖动排序就存在这里）
		$ordered = array();
		foreach ( array_keys( $preferred ) as $field_key ) {
			if ( isset( $defaults[ $field_key ] ) ) {
				$ordered[ $field_key ] = $defaults[ $field_key ];
				unset( $defaults[ $field_key ] );
			}
		}
		// 2) 其余按前端清单的顺序（= 表单字段顺序），清单外的历史字段缀尾

		return array(
			'fields' => $ordered + $defaults,
			'sample' => $sample,
		);
	}

	/* ================================================================== 启动 */
}

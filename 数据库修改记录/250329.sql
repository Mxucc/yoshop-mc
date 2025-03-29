添加规则类型
ALTER TABLE `yoshop`.`yoshop_goods_sku` 
ADD COLUMN `holiday_price` decimal(10, 2) NULL COMMENT '节假日价格' AFTER `goods_price`;

ALTER TABLE `yoshop`.`yoshop_spec` 
ADD COLUMN `spec_type` varchar(255) NULL COMMENT '规则类型' AFTER `create_time`;
/*
 * (c) 2017: 975L <contact@975l.com>
 * (c) 2017: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

SET FOREIGN_KEY_CHECKS=0;

-- ----------------------------
-- Table structure for stripe_payment
-- ----------------------------
-- DROP TABLE IF EXISTS `stripe_payment`;
CREATE TABLE `stripe_payment` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `finished` tinyint(1) UNSIGNED NULL,
    `order_id` varchar(48) DEFAULT NULL,
    `amount` int(8) DEFAULT NULL,
    `vat` tinyint(2) DEFAULT NULL,
    `currency` varchar(3) DEFAULT NULL,
    `action` varchar(128) DEFAULT NULL,
    `description` varchar(512) DEFAULT NULL,
    `stripe_fee` mediumint(8) DEFAULT NULL,
    `stripe_token` varchar(128) DEFAULT NULL,
    `stripe_token_type` varchar(16) DEFAULT NULL,
    `stripe_email` varchar(255) DEFAULT NULL,
    `user_id` int(11) unsigned DEFAULT NULL,
    `user_ip` varchar(48) DEFAULT NULL,
    `creation` datetime DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

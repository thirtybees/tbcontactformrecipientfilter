CREATE TABLE IF NOT EXISTS `PREFIX_cfrf_recipient_rule` (
    `id_cfrf_recipient_rule` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `rule` VARCHAR(255) NOT NULL,
    `type` ENUM(ENUM_VALUES_ROLE_TYPES) NOT NULL,
    PRIMARY KEY (`id_cfrf_recipient_rule`)
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE COLLATE=COLLATE_TYPE;

CREATE TABLE IF NOT EXISTS `PREFIX_cfrf_recipient_rule_activity` (
    `id_cfrf_recipient_rule_activity` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_cfrf_recipient_rule` INT(11) UNSIGNED NOT NULL,
    `ts` DATETIME NOT NULL,
    PRIMARY KEY (`id_cfrf_recipient_rule_activity`),
    FOREIGN KEY `cfrf_rra_rr` (`id_cfrf_recipient_rule`) REFERENCES `PREFIX_cfrf_recipient_rule`(`id_cfrf_recipient_rule`) ON DELETE CASCADE
) ENGINE=ENGINE_TYPE DEFAULT CHARSET=CHARSET_TYPE COLLATE=COLLATE_TYPE;

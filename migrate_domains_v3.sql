-- ============================================================
-- IHOST Domain Management Migration v3
-- Adds: extended status enum, ns_mode + custom NS, domain_logs
-- Run in phpMyAdmin → ihost database
-- ============================================================

USE ihost;

-- 1. Extend statusDomaine enum + add nameserver columns
ALTER TABLE domaine
    MODIFY COLUMN statusDomaine ENUM('active','expired','pending_transfer','suspended') DEFAULT 'active',
    ADD COLUMN ns_mode ENUM('default','custom') DEFAULT 'default' AFTER whois_privacy,
    ADD COLUMN ns1 VARCHAR(255) DEFAULT NULL AFTER ns_mode,
    ADD COLUMN ns2 VARCHAR(255) DEFAULT NULL AFTER ns1,
    ADD COLUMN ns3 VARCHAR(255) DEFAULT NULL AFTER ns2,
    ADD COLUMN ns4 VARCHAR(255) DEFAULT NULL AFTER ns3;

-- 2. Domain-specific append-only activity log
CREATE TABLE IF NOT EXISTS domain_logs (
    idLog     INT           PRIMARY KEY AUTO_INCREMENT,
    domaineId INT           NOT NULL,
    userId    INT           NOT NULL,
    action    VARCHAR(100)  NOT NULL,
    detail    TEXT,
    createdAt TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (domaineId) REFERENCES domaine(idDomaine) ON DELETE CASCADE,
    FOREIGN KEY (userId)    REFERENCES users(idU)         ON DELETE CASCADE
);

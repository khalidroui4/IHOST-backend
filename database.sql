create database ihost;
use ihost;

CREATE TABLE users (
    idU INT PRIMARY KEY AUTO_INCREMENT,
    nameU VARCHAR(100),
    first_name VARCHAR(50) NULL,
    last_name VARCHAR(50) NULL,
    username VARCHAR(50) UNIQUE NULL,
    email VARCHAR(150) UNIQUE,
    passwordU VARCHAR(255),
    roleU ENUM('client','admin') DEFAULT 'client',
    emailVerified BOOLEAN DEFAULT FALSE,
    location VARCHAR(100) NULL,
    website VARCHAR(255) NULL,
    bio TEXT NULL,
    interests VARCHAR(255) NULL,
    instagram VARCHAR(50) NULL,
    twitter VARCHAR(50) NULL,
    avatar VARCHAR(255) NULL,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE service (
    idService INT PRIMARY KEY AUTO_INCREMENT,
    nameService VARCHAR(100),
    descriptionS TEXT,
    price DECIMAL(10,2),
    durationMonths INT,
    isActive BOOLEAN DEFAULT TRUE,
    typeService VARCHAR(50) DEFAULT 'hosting'
);

CREATE TABLE subscription (
    idSub INT PRIMARY KEY AUTO_INCREMENT,
    userId INT,
    serviceId INT,
    startDate DATE,
    endDate DATE,
    statusSub ENUM('active','expired','suspended'),
    FOREIGN KEY (userId) REFERENCES users(idU),
    FOREIGN KEY (serviceId) REFERENCES service(idService)
);

CREATE TABLE orders (
    idOrder INT PRIMARY KEY AUTO_INCREMENT,
    userId INT,
    totalAmount DECIMAL(10,2),
    statusOrder ENUM('pending','paid','cancelled'),
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userId) REFERENCES users(idU)
);

CREATE TABLE order_items (
    idOrderItem INT PRIMARY KEY AUTO_INCREMENT,
    orderId INT,
    serviceId INT,
    durationMonths INT,
    price DECIMAL(10,2),
    FOREIGN KEY (orderId) REFERENCES orders(idOrder) ON DELETE CASCADE,
    FOREIGN KEY (serviceId) REFERENCES service(idService)
);

CREATE TABLE payement (
    idPay INT PRIMARY KEY AUTO_INCREMENT,
    orderId INT,
    method VARCHAR(50),
    amount DECIMAL(10,2),
    statusPay ENUM('success','failed','pending'),
    paidAt TIMESTAMP,
    FOREIGN KEY (orderId) REFERENCES orders(idOrder)
);

CREATE TABLE facture (
    idFacture INT PRIMARY KEY AUTO_INCREMENT,
    orderId INT,
    invoiceNumber VARCHAR(50),
    amount DECIMAL(10,2),
    statusFacture ENUM('unpaid','paid'),
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (orderId) REFERENCES orders(idOrder)
);

CREATE TABLE domaine (
    idDomaine INT PRIMARY KEY AUTO_INCREMENT,
    userId INT,
    domainName VARCHAR(255) UNIQUE,
    expirationDate DATE,
    statusDomaine ENUM('active','expired'),
    FOREIGN KEY (userId) REFERENCES users(idU)
);

CREATE TABLE notification (
    idNotification INT PRIMARY KEY AUTO_INCREMENT,
    userId INT,
    message TEXT,
    isRead BOOLEAN DEFAULT FALSE,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userId) REFERENCES users(idU)
);

CREATE TABLE support (
    idSupport INT PRIMARY KEY AUTO_INCREMENT,
    userId INT,
    subjectSupport VARCHAR(255),
    statusSupport ENUM('open','closed'),
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userId) REFERENCES users(idU)
);

CREATE TABLE support_messages (
    idMessage INT PRIMARY KEY AUTO_INCREMENT,
    ticketId INT,
    sender ENUM('client','admin'),
    message TEXT,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticketId) REFERENCES support(idSupport)
);

CREATE TABLE chatbot (
    idChat INT PRIMARY KEY AUTO_INCREMENT,
    userId INT,
    question TEXT,
    response TEXT,
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userId) REFERENCES users(idU)
);

CREATE TABLE cart (
    idCart INT AUTO_INCREMENT PRIMARY KEY,
    userId INT,
    serviceId INT,
    durationMonths INT DEFAULT 1,
    FOREIGN KEY (userId) REFERENCES users(idU),
    FOREIGN KEY (serviceId) REFERENCES service(idService)
);

CREATE TABLE log (
    idLog INT PRIMARY KEY AUTO_INCREMENT,
    userId INT NULL,
    actionLog VARCHAR(255),
    createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (userId) REFERENCES users(idU) ON DELETE SET NULL
);

DELIMITER $$
CREATE FUNCTION is_domain_expired(expDate DATE)
RETURNS BOOLEAN
DETERMINISTIC
BEGIN
    RETURN expDate < CURDATE();
END $$
DELIMITER ;

DELIMITER $$
CREATE PROCEDURE activate_subscription(
    IN p_user INT,
    IN p_service INT
)
BEGIN
    DECLARE v_duration INT;
    SELECT durationMonths
    INTO v_duration
    FROM service
    WHERE idService = p_service
    LIMIT 1;
    IF v_duration IS NOT NULL THEN
        INSERT INTO subscription(
            userId,
            serviceId,
            startDate,
            endDate,
            statusSub
        )
        VALUES(
            p_user,
            p_service,
            CURDATE(),
            DATE_ADD(CURDATE(), INTERVAL v_duration MONTH),
            'active'
        );
    END IF;
    IF v_duration IS NULL THEN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Service not found';
END IF;
END $$
DELIMITER ;

DELIMITER $$
CREATE PROCEDURE create_notification(
    IN uid INT,
    IN msg TEXT
)
BEGIN
    INSERT INTO notification(userId,message)
    VALUES(uid,msg);
END $$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER payment_success
AFTER UPDATE ON payement
FOR EACH ROW
BEGIN
    IF NEW.statusPay='success' THEN
        UPDATE facture
        SET statusFacture='paid'
        WHERE orderId = NEW.orderId;
    END IF;
END $$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER log_user_creation
AFTER INSERT ON users
FOR EACH ROW
BEGIN
    INSERT INTO log(userId,actionLog)
    VALUES(NEW.idU,'User created');
END $$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER check_domain_status
BEFORE UPDATE ON domaine
FOR EACH ROW
BEGIN
    IF NEW.expirationDate < CURDATE() THEN
        SET NEW.statusDomaine='expired';
    END IF;
END $$
DELIMITER ;

DELIMITER $$
CREATE PROCEDURE notify_expiring_domains()
BEGIN
    DECLARE done INT DEFAULT 0;	
    DECLARE uid BIGINT;
    DECLARE cur CURSOR FOR
        SELECT userId
        FROM domaine
        WHERE expirationDate <= DATE_ADD(CURDATE(), INTERVAL 7 DAY);
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO uid;
        IF done THEN
            LEAVE read_loop;
        END IF;
        INSERT INTO notification(userId,message)
        VALUES(uid,'Your domain will expire soon.');
    END LOOP;
    CLOSE cur;
END $$
DELIMITER ;


INSERT INTO service (nameService, descriptionS, price, durationMonths, isActive, typeService) VALUES
('Starter', '1 Site, 10 GB SSD, SSL Gratuit', 29.00, 1, 1, 'hosting'),
('Pro', '10 Sites, 50 GB SSD, Backup Auto', 59.00, 1, 1, 'hosting'),
('Business', 'Sites illimités, 100 GB SSD, Vitesse Max', 99.00, 1, 1, 'hosting'),
('Cloud Basic', '2 vCPU, 4 GB RAM, 80 GB SSD', 149.00, 1, 1, 'cloud'),
('Cloud Pro', '4 vCPU, 8 GB RAM, 160 GB SSD', 249.00, 1, 1, 'cloud'),
('Cloud Ent.', '8 vCPU, 16 GB RAM, 320 GB SSD', 449.00, 1, 1, 'cloud'),
('Domaine .MA', 'Identité Marocaine, DNS Manager', 150.00, 12, 1, 'domain'),
('Domaine .COM', 'Standard Mondial, Full Control', 120.00, 12, 1, 'domain'),
('Domaine .ONLINE', 'Promo Limitée, Instant Active', 40.00, 12, 1, 'domain'),
('Positive SSL', 'Validation de Domaine, Cadenas Vert', 89.00, 12, 1, 'ssl'),
('Wildcard SSL', 'Sous-domaines illimités, Sécurité Max', 890.00, 12, 1, 'ssl'),
('EV SSL', 'Barre verte, Validation Entreprise', 1490.00, 12, 1, 'ssl'),
('Mail Basic', '5 Go Stockage, Antispam', 19.00, 1, 1, 'email'),
('Mail Pro', '50 Go Stockage, Calendrier partagé', 49.00, 1, 1, 'email'),
('Exchange', '100 Go Stockage, Synchro ActiveSync', 99.00, 1, 1, 'email'),
('CLOUD STARTER', '1 Site Web (LVE Restricted), 10 GB Stockage (NVMe Gen4), Email Pro (Exim/IMAP), SSL Gratuit (Let''s Encrypt), Support 24/7 (Ticket/Chat)', 39.00, 1, 1, 'hosting'),
('VPS PRO-X', '4 vCore EPYC (Milan/Genoa), 8 GB DDR4 RAM (ECC Registered), 100 GB Stockage (Raid 10 NVMe), Snapshot (Daily Backup), Network (1 Gbps Port)', 129.00, 1, 1, 'cloud'),
('BUSINESS CORE', 'Unlimited Sites (Apache/Nginx), Unlimited Space (Pure NVMe), WAF Protection (BitNinja/Luxe), Daily Backups (JetBackup), Dedicated IP (IPv4/IPv6)', 99.00, 1, 1, 'hosting');

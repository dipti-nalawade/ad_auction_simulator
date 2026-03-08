CREATE DATABASE IF NOT EXISTS ad_auction_simulator
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE ad_auction_simulator;

CREATE TABLE IF NOT EXISTS bidders (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255)   NOT NULL,
    budget      DECIMAL(12, 2) NOT NULL,
    created_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS auctions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slot_name     VARCHAR(255)                        NOT NULL,
    reserve_price DECIMAL(12, 2)                      NOT NULL,
    status        ENUM('pending', 'active', 'closed') NOT NULL DEFAULT 'pending',
    created_at    TIMESTAMP                           NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bids (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    auction_id   INT UNSIGNED   NOT NULL,
    bidder_id    INT UNSIGNED   NOT NULL,
    amount       DECIMAL(12, 2) NOT NULL,
    submitted_at TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bids_auction FOREIGN KEY (auction_id) REFERENCES auctions (id) ON DELETE CASCADE,
    CONSTRAINT fk_bids_bidder  FOREIGN KEY (bidder_id)  REFERENCES bidders (id)  ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS auction_results (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    auction_id       INT UNSIGNED   NOT NULL UNIQUE,
    winner_bidder_id INT UNSIGNED   NOT NULL,
    clearing_price   DECIMAL(12, 2) NOT NULL,
    winner_bid       DECIMAL(12, 2) NOT NULL,
    margin           DECIMAL(12, 2) NOT NULL,
    resolved_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_results_auction FOREIGN KEY (auction_id)       REFERENCES auctions (id) ON DELETE CASCADE,
    CONSTRAINT fk_results_bidder  FOREIGN KEY (winner_bidder_id) REFERENCES bidders  (id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cpm_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    auction_id INT UNSIGNED   NOT NULL,
    cpm_value  DECIMAL(12, 4) NOT NULL,
    logged_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cpm_auction FOREIGN KEY (auction_id) REFERENCES auctions (id) ON DELETE CASCADE
);

-- phpMyAdmin SQL Dump
-- version 4.4.15.10
-- https://www.phpmyadmin.net
--
-- Poèítaè: localhost
-- Vytvoøeno: Pát 16. led 2026, 19:05
-- Verze serveru: 5.5.68-MariaDB
-- Verze PHP: 5.4.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

--
-- Databáze: `vpn_stats`
--

-- --------------------------------------------------------

--
-- Struktura tabulky `vpn_connections`
--

CREATE TABLE IF NOT EXISTS `vpn_connections` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `connect_date` date NOT NULL,
  `connect_time` time NOT NULL,
  `disconnect_date` date NOT NULL,
  `disconnect_time` time NOT NULL,
  `traffic_down_mb` decimal(10,3) DEFAULT '0.000',
  `traffic_up_mb` decimal(10,3) DEFAULT '0.000',
  `internal_traffic_mb` decimal(10,3) DEFAULT '0.000',
  `tx_packets` bigint(20) DEFAULT '0',
  `rx_packets` bigint(20) DEFAULT '0',
  `internal_tx_packets` bigint(20) DEFAULT '0',
  `internal_rx_packets` bigint(20) DEFAULT '0',
  `caller_ip` varchar(50) DEFAULT NULL,
  `remote_ip` varchar(50) DEFAULT NULL,
  `rx_drops` int(11) DEFAULT '0',
  `tx_drops` int(11) DEFAULT '0',
  `rx_errors` int(11) DEFAULT '0',
  `tx_errors` int(11) DEFAULT '0',
  `session_time` int(11) DEFAULT '0' COMMENT 'Session duration in seconds',
  `processed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='VPN connection statistics';

--
-- Klíèe pro exportované tabulky
--

--
-- Klíèe pro tabulku `vpn_connections`
--
ALTER TABLE `vpn_connections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_date` (`disconnect_date`),
  ADD KEY `idx_user` (`username`),
  ADD KEY `idx_processed` (`processed_at`),
  ADD KEY `idx_session` (`session_time`);

--
-- AUTO_INCREMENT pro tabulky
--

--
-- AUTO_INCREMENT pro tabulku `vpn_connections`
--
ALTER TABLE `vpn_connections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

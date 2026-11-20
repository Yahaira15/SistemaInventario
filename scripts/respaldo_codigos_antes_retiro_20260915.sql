-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: inventarioequipos
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `productos`
--

DROP TABLE IF EXISTS `productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `productos` (
  `id_producto` int(11) NOT NULL AUTO_INCREMENT,
  `id_categoria` int(11) NOT NULL,
  `codigo` varchar(50) NOT NULL,
  `descripcion` varchar(255) NOT NULL,
  `estado` enum('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
  `id_area` int(11) NOT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `version` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id_producto`),
  UNIQUE KEY `uq_producto_codigo` (`codigo`),
  UNIQUE KEY `uq_producto_categoria` (`id_producto`,`id_categoria`),
  KEY `idx_producto_categoria` (`id_categoria`),
  KEY `idx_producto_id_area` (`id_area`) USING BTREE,
  CONSTRAINT `fk_producto_area` FOREIGN KEY (`id_area`) REFERENCES `areas` (`id_area`) ON UPDATE CASCADE,
  CONSTRAINT `fk_producto_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categorias` (`id_categoria`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `productos`
--

LOCK TABLES `productos` WRITE;
/*!40000 ALTER TABLE `productos` DISABLE KEYS */;
INSERT INTO `productos` VALUES (2,2,'ADP-TM001','Holaaa','ACTIVO',1,'2026-09-04 15:26:58','2026-09-08 10:23:36',1),(3,4,'ADP-PC001','','ACTIVO',2,'2026-09-07 15:57:35','2026-09-08 08:56:07',1),(6,4,'ADP-PC002','Holaaaa','ACTIVO',3,'2026-09-07 16:45:32','2026-09-08 11:17:55',1),(7,4,'ADP-PC003','Contabilidad','ACTIVO',3,'2026-09-08 16:40:44',NULL,1);
/*!40000 ALTER TABLE `productos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `producto_codigos`
--

DROP TABLE IF EXISTS `producto_codigos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `producto_codigos` (
  `id_producto_codigo` int(11) NOT NULL AUTO_INCREMENT,
  `id_producto` int(11) NOT NULL,
  `id_codigo` int(11) NOT NULL,
  `id_categoria` int(11) NOT NULL,
  `fecha_inicio` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_fin` datetime DEFAULT NULL,
  `origen` enum('MIGRACION','ASIGNACION') NOT NULL DEFAULT 'ASIGNACION',
  `producto_vigente` int(11) GENERATED ALWAYS AS (case when `fecha_fin` is null then `id_producto` else NULL end) STORED,
  `codigo_vigente` int(11) GENERATED ALWAYS AS (case when `fecha_fin` is null then `id_codigo` else NULL end) STORED,
  PRIMARY KEY (`id_producto_codigo`),
  UNIQUE KEY `uq_producto_codigo_vigente` (`producto_vigente`),
  UNIQUE KEY `uq_codigo_producto_vigente` (`codigo_vigente`),
  KEY `fk_historial_producto_categoria` (`id_producto`,`id_categoria`),
  KEY `fk_historial_codigo_categoria` (`id_codigo`,`id_categoria`),
  CONSTRAINT `fk_historial_codigo_categoria` FOREIGN KEY (`id_codigo`, `id_categoria`) REFERENCES `codigos_inventario` (`id_codigo`, `id_categoria`),
  CONSTRAINT `fk_historial_producto_categoria` FOREIGN KEY (`id_producto`, `id_categoria`) REFERENCES `productos` (`id_producto`, `id_categoria`),
  CONSTRAINT `ck_codigo_fechas` CHECK (`fecha_fin` is null or `fecha_fin` >= `fecha_inicio`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `producto_codigos`
--

LOCK TABLES `producto_codigos` WRITE;
/*!40000 ALTER TABLE `producto_codigos` DISABLE KEYS */;
INSERT INTO `producto_codigos` VALUES (1,2,1,2,'2026-09-11 10:03:19',NULL,'MIGRACION',2,1),(2,3,2,4,'2026-09-11 10:03:19',NULL,'MIGRACION',3,2),(3,6,3,4,'2026-09-11 10:03:19',NULL,'MIGRACION',6,3),(4,7,4,4,'2026-09-11 10:03:19',NULL,'MIGRACION',7,4);
/*!40000 ALTER TABLE `producto_codigos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `codigos_inventario`
--

DROP TABLE IF EXISTS `codigos_inventario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `codigos_inventario` (
  `id_codigo` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(255) NOT NULL,
  `id_categoria` int(11) NOT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT NULL,
  PRIMARY KEY (`id_codigo`),
  UNIQUE KEY `codigo` (`codigo`),
  UNIQUE KEY `uq_codigo_categoria` (`id_codigo`,`id_categoria`),
  KEY `idx_codigo_inventario_id_categoria` (`id_categoria`),
  CONSTRAINT `fk_codigo_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categorias` (`id_categoria`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `codigos_inventario`
--

LOCK TABLES `codigos_inventario` WRITE;
/*!40000 ALTER TABLE `codigos_inventario` DISABLE KEYS */;
INSERT INTO `codigos_inventario` VALUES (1,'ADP-TM001',2,'2026-09-11 15:03:19',NULL),(2,'ADP-PC001',4,'2026-09-11 15:03:19',NULL),(3,'ADP-PC002',4,'2026-09-11 15:03:19',NULL),(4,'ADP-PC003',4,'2026-09-11 15:03:19',NULL);
/*!40000 ALTER TABLE `codigos_inventario` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categorias`
--

DROP TABLE IF EXISTS `categorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categorias` (
  `id_categoria` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `descripcion` varchar(120) NOT NULL,
  `estado` enum('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
  `prefijo_codigo` varchar(20) NOT NULL,
  `ultimo_consecutivo` int(10) unsigned NOT NULL DEFAULT 0,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_categoria`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categorias`
--

LOCK TABLES `categorias` WRITE;
/*!40000 ALTER TABLE `categorias` DISABLE KEYS */;
INSERT INTO `categorias` VALUES (2,'CELULAR','Dispositivo movil tactil','ACTIVO','ADP-TM',1,'2026-08-03 14:05:35','2026-09-15 11:07:15'),(4,'COMPUTO','Equipos de computo tanto de escritorio como portatil','ACTIVO','ADP-PC',3,'2026-08-03 15:44:59','2026-09-15 11:07:15'),(6,'Programa','Programas elaborados para la empresa','ACTIVO','',0,'2026-08-14 13:06:24',NULL),(10,'Aplicativos','Aplicativos desarrollados','ACTIVO','',0,'2026-08-18 13:52:33',NULL),(11,'Correos','Correos asignados','ACTIVO','',0,'2026-08-18 13:55:29',NULL);
/*!40000 ALTER TABLE `categorias` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-15 12:13:01

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
  KEY `idx_producto_categoria` (`id_categoria`),
  KEY `idx_producto_id_area` (`id_area`) USING BTREE,
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
  KEY `idx_codigo_inventario_id_categoria` (`id_categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `codigos_inventario`
--

LOCK TABLES `codigos_inventario` WRITE;
/*!40000 ALTER TABLE `codigos_inventario` DISABLE KEYS */;
/*!40000 ALTER TABLE `codigos_inventario` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `producto_asignaciones`
--

DROP TABLE IF EXISTS `producto_asignaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `producto_asignaciones` (
  `id_producto_asignacion` int(10) NOT NULL AUTO_INCREMENT,
  `id_producto` int(10) NOT NULL,
  `id_usuario` int(10) unsigned NOT NULL,
  `fecha_asignacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_devolucion` datetime DEFAULT NULL,
  PRIMARY KEY (`id_producto_asignacion`),
  KEY `idx_producto_asignacion_id_producto` (`id_producto`),
  KEY `idx_producto_asignacion_id_usuario` (`id_usuario`),
  CONSTRAINT `fk_asignaciones_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON UPDATE CASCADE,
  CONSTRAINT `fk_asignaciones_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `producto_asignaciones`
--

LOCK TABLES `producto_asignaciones` WRITE;
/*!40000 ALTER TABLE `producto_asignaciones` DISABLE KEYS */;
/*!40000 ALTER TABLE `producto_asignaciones` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-11 10:03:06

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
-- Table structure for table `areas`
--

DROP TABLE IF EXISTS `areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `areas` (
  `id_area` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `estado` enum('ACTIVO','INACTIVO') NOT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_area`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `areas`
--

LOCK TABLES `areas` WRITE;
/*!40000 ALTER TABLE `areas` DISABLE KEYS */;
INSERT INTO `areas` VALUES (1,'Sistemas','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(2,'Recursos Humanos','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(3,'Contabilidad','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(4,'Administración','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(5,'Gerencia','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(6,'Compras','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(7,'Ventas','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(8,'Almacén','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(9,'Mantenimiento','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(10,'Logística','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(11,'Seguridad','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(12,'Servicio al Cliente','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(13,'Marketing','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(14,'Producción','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(15,'Tesorería','ACTIVO','2026-09-01 14:00:56','2026-09-01 14:00:56'),(16,'Prueba','ACTIVO','2026-09-01 17:27:39','2026-09-01 17:27:39');
/*!40000 ALTER TABLE `areas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usuarios` (
  `id_usuario` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `cedula` varchar(20) NOT NULL,
  `nombre` varchar(80) NOT NULL,
  `apellido` varchar(80) NOT NULL,
  `correo` varchar(255) NOT NULL,
  `area` varchar(80) NOT NULL,
  `rol` enum('ADMINISTRADOR','FUNCIONARIO') NOT NULL DEFAULT 'FUNCIONARIO',
  `estado` enum('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `id_area` int(11) NOT NULL,
  PRIMARY KEY (`id_usuario`),
  UNIQUE KEY `cedula` (`cedula`),
  UNIQUE KEY `correo` (`correo`),
  KEY `uq_usuario_area` (`id_area`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES (1,'102030','Carlos','Ramírez','carlos@gmail.com','Contabilidad','FUNCIONARIO','ACTIVO','2026-07-27 15:12:53','2026-08-10 17:29:52',0),(2,'753159','Yahaira','Tellez','yahaira@gmail.com','Administración TI','ADMINISTRADOR','ACTIVO','2026-07-27 15:13:38','2026-08-31 14:10:04',0),(18,'123456','Admin','Administrador','admin@gmail.com','Tecnología','FUNCIONARIO','ACTIVO','2026-07-28 15:39:12','2026-08-12 16:34:16',0),(22,'654321','Admin','Principal','yahairatellez2007@gmail.com','TI','ADMINISTRADOR','ACTIVO','2026-07-28 16:20:27','2026-08-26 14:52:52',0),(25,'122333','prueba','Principal','nada@gmail.com','Contabilidad','FUNCIONARIO','ACTIVO','2026-08-11 14:56:51','2026-08-11 14:56:51',0),(26,'1001001001','Laura','Martínez','laura.martinez@empresa.com','Administración','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-12 17:16:36',0),(27,'1001001002','Carlos','Rodríguez','carlos.rodriguez@empresa.com','Sistemas','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-12 17:16:36',0),(28,'1001001003','Andrea','Gómez','andrea.gomez@empresa.com','Contabilidad','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-12 17:16:36',0),(29,'1001001004','Miguel','Hernández','miguel.hernandez@empresa.com','Logística','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-12 17:16:36',0),(30,'1001001005','Daniela','Ramírez','daniela.ramirez@empresa.com','Recursos Humanos','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-12 17:16:36',0),(31,'1001001006','Sebastián','Torres','sebastian.torres@empresa.com','Sistemas','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-12 17:16:36',0),(32,'1001001007','Valentina','Castro','valentina.castro@empresa.com','Contabilidad','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-25 13:17:34',0),(33,'1001001008','Juan','Moreno','juan.moreno@empresa.com','Almacén','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-12 17:16:36',0),(34,'1001001009','Camila','Rojas','camila.rojas@empresa.com','Comercial','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-12 17:16:36',0),(35,'1001001010','Santiago','Vargas','santiago.vargas@empresa.com','Soporte','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-12 17:16:36',0),(36,'1001001011','Natalia','Jiménez','natalia.jimenez@empresa.com','Contabilidad','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-28 16:33:20',0),(37,'1001001012','Felipe','Mendoza','felipe.mendoza@empresa.com','Logística','FUNCIONARIO','INACTIVO','2026-08-12 17:16:36','2026-08-18 13:27:09',0),(38,'1001001013','Mariana','Pérez','mariana.perez@empresa.com','Administración','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-18 13:23:20',0),(39,'1001001014','Alejandro','Sánchez','alejandro.sanchez@empresa.com','Sistemas','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-12 17:16:36',0),(40,'1001001015','Paula','Gutiérrez','paula.gutierrez@empresa.com','Recursos Humanos','FUNCIONARIO','ACTIVO','2026-08-12 17:16:36','2026-08-12 17:16:36',0),(41,'987654','Hola','Adios','holaa@gmail.com','Administración TI','FUNCIONARIO','ACTIVO','2026-08-18 13:49:51','2026-08-18 13:49:51',0),(42,'987654321','JONATHAN','MORENO','jefe.ti@adpsa.com.co','Tecnologia','FUNCIONARIO','ACTIVO','2026-08-25 17:10:25','2026-08-25 17:10:25',0),(46,'654987','Alejandro','Soza','alejandro@gmail.com','Contable','FUNCIONARIO','ACTIVO','2026-08-28 14:25:58','2026-08-28 14:27:38',0),(47,'369852','Prueba','Area','pruebaa@gmail.com','Comercial','FUNCIONARIO','ACTIVO','2026-08-28 14:55:47','2026-08-28 14:55:47',0);
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-02 10:31:32

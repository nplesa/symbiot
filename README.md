# Symbiot

A modern GPS tracking application built with Laravel and OpenLayers for recording, processing and visualizing location data.

![Laravel](https://img.shields.io/badge/Laravel-13.x-red)
![PHP](https://img.shields.io/badge/PHP-8.4-blue)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5-purple)
![OpenLayers](https://img.shields.io/badge/OpenLayers-Map-green)
![License](https://img.shields.io/badge/license-MIT-success)

---

## Overview

Symbiot is a web application that allows users to manage GPS tracking sessions and visualize recorded routes on an interactive map.

The application is built using Laravel for the backend and OpenLayers for rendering maps, while Vite and Bootstrap provide a modern frontend development workflow.

---

## Features

- User authentication
- Device management
- GPS tracking sessions
- Route visualization using OpenLayers
- GeoJSON support
- Daily track filtering
- Interactive map
- Geoapify integration
- REST API
- Seeders with realistic GPS routes
- Queue processing
- Responsive Bootstrap interface

---

## Tech Stack

Backend

- Laravel 13
- PHP 8.4
- MySQL
- Sanctum

Frontend

- Bootstrap 5
- Vite
- OpenLayers
- JavaScript (ES Modules)

Maps

- OpenLayers
- Geoapify
- OpenStreetMap
- OSRM Routing API

Development

- PHPStan
- Laravel Pint
- Composer
- npm

---

## Installation

Clone the repository

```bash
git clone https://github.com/nplesa/symbiot.git
cd symbiot
```

Install dependencies

```bash
composer install
npm install
```

Copy the environment file

```bash
cp .env.example .env
```

Generate the application key

```bash
php artisan key:generate
```

Configure the database in `.env`.

Run migrations

```bash
php artisan migrate
```

Seed demo data

```bash
php artisan db:seed
```

Build frontend assets

```bash
npm run build
```

Start the application

```bash
php artisan serve
```

---

## Project Structure

```
app/
 ├── Http/
 ├── Jobs/
 ├── Models/
 ├── Services/

resources/
 ├── css/
 ├── js/

routes/
database/
```

---

## Screenshots

### Login

> docs/images/login.png

### Dashboard

> docs/images/dashboard.png

### GPS Tracking

> docs/images/tracking.png

---

## API

Example endpoints

```
GET    /tracking/sessions
GET    /tracking/{session}/points
POST   /api/login
POST   /api/logout
```

---

## Development

Run PHPStan

```bash
vendor/bin/phpstan analyse
```

Run Pint

```bash
vendor/bin/pint
```

Build assets

```bash
npm run dev
```

---

## Future Improvements

- Live GPS tracking
- Route replay
- Speed analysis
- Elevation profile
- GPX import/export
- Heatmap visualization
- Statistics dashboard

---

## License

This project is licensed under the MIT License.

---

## Author

Nicu Pleșa

GitHub

https://github.com/nplesa
<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## A&M Sports Control

Backend Laravel de contabilidad para bancas deportivas. La API de producción usa:

```text
https://amsport.bsolutions.dev/api/v1/
```

### Cambios actuales

- Autenticación Sanctum y contabilidad por empresa activa.
- Cobros, adelantos, libro mayor y caja chica con operaciones idempotentes.
- Cuadre semanal por banca con ventas, premios pagados y efectivo entregado.
- Comisión configurable, con `20%` como valor predeterminado en Android.
- Fórmula del cuadre: `ventas - premios - comisión + efectivo entregado`.
- Cada cuadre crea asientos contables y el efectivo entregado crea una salida de caja.
- La misma banca no puede registrar dos veces el mismo período semanal.

### Endpoints del cuadre semanal

- `GET /api/v1/weekly-settlements?branch_id={id}`
- `POST /api/v1/weekly-settlements`

El `POST` requiere el header `Idempotency-Key` como UUID y estos campos:

```json
{
  "branch_id": 1,
  "week_start": "2026-09-14",
  "week_end": "2026-09-20",
  "sales_amount": "6000.00",
  "prizes_amount": "3000.00",
  "commission_rate": "20.00",
  "cash_delivered_amount": "2000.00",
  "notes": "Opcional"
}
```

### Verificación local

```powershell
php artisan test
```

### Estado de producción

El commit `a585786` está desplegado en `C:\xampp\htdocs\amsport-api`. La migración `2026_09_19_100000_create_weekly_settlements_table` se ejecutó correctamente sobre `amsport_api` en el batch `[2]`. Los permisos DDL temporales fueron revocados y la cuenta de aplicación conserva sólo permisos de lectura/escritura.

Para futuras migraciones que requieran DDL, usar una cuenta administrativa o conceder permisos temporales únicamente sobre `amsport_api`, y revocarlos después. Ejecutar únicamente sobre este proyecto:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\amsport-api\artisan migrate --force
C:\xampp\php\php.exe C:\xampp\htdocs\amsport-api\artisan optimize
```

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

# Portfolio API 👩‍💻

## Description

This is the API project for my Vue.js portfolio application. It uses PHP and the Symfony framework, alongside a variety of other dependencies.

## Tech Stack

- PHP >= 8.3
- Composer 2.x
## Features

- API Platform integration: Utilizes API Platform for building APIs.
- Security : Implements JWT authentication for secure access and password.
- Fixtures : Implements fixture to create a user admin.
- User Management: Provides user entities with role-based access control and password management.

## Installation
1. Clone the repository:

```
git clone https://github.com/carolinechap/portfolio-api.git
```

2. Install dependencies:

``` 
composer install
```

3. Set up environment variables:
```
cp .env.example .env
# Configure your .env file as needed
```

4. Create a database
```
php bin/console doctrine:database:create
```
5. Run migration
```
php bin/console doctrine:migrations:migrate
```

## Scripts

- `cache:clear`: Clears the cache
- `lexik:jwt:generate-keypair`: Generate public/private key for use in your application.

A reusable Laravel package for user-level discounts, featuring deterministic stacking, usage caps, concurrency-safe application, and full audit logging.

1. Installation (Local Development)
    These steps link your local package source code into your host Laravel application using Composer's path repository feature.

Step 1: Host Application Configuration (composer.json)
    Modify your main Laravel project's composer.json to include the repositories section to link the package path, and adjust the minimum-stability for local development.

    // --- laravel_project/composer.json (REQUIRED MODIFICATIONS) ---

    "minimum-stability": "dev",    // <--- Set for local development
    "prefer-stable": true,         
    "repositories": [
        {
            "type": "path",
            "url": "packages/vendor/laravel-user-discounts", // <--- The local package folder
            "options": { "symlink": true }
        }
    ],
    "require": {
        // ... other requirements
        "vendor/laravel-user-discounts": "dev-main"  // <--- Require the package using its internal name
    },

Step 2: Run Composer Update
    Execute this command from your project root. Composer will detect the package via the path repository, load its dependencies (like orchestra/testbench), and register its PSR-4 autoloading.

    composer update

// ------------------------------------------------------------------------------------------------------

2. Configuration and Database Setup

Step 1: Clear Cache and Publish Config
    Clear all caches to ensure Laravel properly discovers the new service provider, and then publish the package configuration using the designated tag.

    php artisan cache:clear 
    php artisan config:clear

    // Publish configuration file (user_discounts.php)
    php artisan vendor:publish --tag=config

    The configuration is now available at config/user_discounts.php.

Step 2: Run Migrations
    Apply the database schema changes for the discounts, user_discounts, and discount_audits tables:

    php artisan migrate

// ------------------------------------------------------------------------------------------------------

3. Usage
    The main functions are exposed via the DiscountManager class, which is bound to the service container under the alias user.discounts.

    use LaravelUserDiscounts\Services\DiscountManager;

    // Get the Manager instance
    $manager = app(DiscountManager::class);

    // 1. Assign: Give user John Doe a discount that can be used up to 2 times
    $manager->assign($johnDoe, 'HOLIDAY25', 2);

    // 2. EligibleFor: Get discounts for an item of type X
    $eligible = $manager->eligibleFor($johnDoe); 

    // 3. Apply: Apply discounts to a value, atomically incrementing usage
    $result = $manager->apply($johnDoe, 150.00, $incrementUsage = true); 

    echo "Discount Applied: " . $result['discount_value'] . "\n";
    echo "Final Price: " . $result['final_value'] . "\n";

    // 4. Revoke: Remove the user's eligibility for the discount
    $manager->revoke($johnDoe, 'HOLIDAY25');

// ------------------------------------------------------------------------------------------------------

4. Verification
    Run Unit Tests (Required Acceptance)
    Run the unit tests included in the package to verify the complex logic (stacking, capping, concurrency, and usage limits):
    
    vendor/bin/phpunit packages/vendor/laravel-user-discounts/tests

// ------------------------------------------------------------------------------------------------------
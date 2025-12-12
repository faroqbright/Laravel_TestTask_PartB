<?php

namespace LaravelUserDiscounts\Tests\Unit;

use Orchestra\Testbench\TestCase;
use LaravelUserDiscounts\Models\Discount;
use LaravelUserDiscounts\Models\UserDiscount;
use LaravelUserDiscounts\Services\DiscountManager;
use LaravelUserDiscounts\UserDiscountsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use LaravelUserDiscounts\Events\DiscountApplied;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DiscountApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected $manager;
    protected $user;

    // --- ORCHESTRA TESTBENCH SETUP ---

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        // 1. Set up a minimal 'users' table (required by the package)
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamps();
        });

        // 2. Set config defaults
        $app['config']->set('user_discounts.user_model', 'Illuminate\Foundation\Auth\User');
        $app['config']->set('user_discounts.stacking_order', 'percentage'); 
    }

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app)
    {
        return [
            UserDiscountsServiceProvider::class,
        ];
    }
    
    /**
     * Setup the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure migrations from the package are run
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        
        // 1. Create a minimal test user (required by $this->user)
        // Note: Using the base Laravel User model provided by Testbench's environment setup
        $this->user = \Illuminate\Foundation\Auth\User::create([
            'email' => 'test@user.com',
            'password' => 'secret',
        ]);
        
        // 2. Initialize the DiscountManager (required by $this->manager)
        $this->manager = new DiscountManager();
    }
    
    // --- TESTS START HERE ---

    /** @test */
    public function discount_application_and_usage_cap_logic_are_correct(): void
    {
        Event::fake();
        $initialPrice = 100.00;

        // 1. Fixed Discount: $10 (Per-user cap: 2)
        $fixed = Discount::create(['code' => 'F10', 'type' => 'fixed', 'value' => 10.00, 'is_active' => true]);
        $this->manager->assign($this->user, $fixed->code, 2); 

        // 2. Percentage Discount: 50% (Per-user cap: 1)
        $percentage = Discount::create(['code' => 'P50', 'type' => 'percentage', 'value' => 0.50, 'is_active' => true]);
        $this->manager->assign($this->user, $percentage->code, 1); 

        // TEST 1: First Apply (Stacking: P50 then F10 - due to default config)
        // $100 -> $50 (50%) -> $40 (F10)
        $result1 = $this->manager->apply($this->user, $initialPrice); 
        $this->assertEquals(40.00, $result1['final_value']);
        
        $percUD = UserDiscount::where('discount_id', $percentage->id)->first();
        $fixedUD = UserDiscount::where('discount_id', $fixed->id)->first();
        $this->assertEquals(1, $percUD->times_used, 'P50 usage'); // Capped
        $this->assertEquals(1, $fixedUD->times_used, 'F10 usage'); // Not yet capped
        Event::assertDispatched(DiscountApplied::class); // Verify Event Dispatching (Requirement)

        // TEST 2: Second Apply (P50 Capped, only F10 applies)
        // $100 -> $90 (F10)
        $result2 = $this->manager->apply($this->user, $initialPrice); 
        $this->assertEquals(90.00, $result2['final_value']);
        
        $percUD->refresh(); $fixedUD->refresh();
        $this->assertEquals(1, $percUD->times_used, 'P50 still capped');
        $this->assertEquals(2, $fixedUD->times_used, 'F10 now capped');

        // TEST 3: Third Apply (Both Capped - Usage Cap Enforced)
        $result3 = $this->manager->apply($this->user, $initialPrice); 
        $this->assertEquals(100.00, $result3['final_value']); // No discounts applied
    }

    /** @test */
    public function stacking_and_percentage_cap_enforced_correctly(): void
    {
        // Global Cap set in Environment (e.g., 0.80 or 80%)
        $this->app['config']->set('user_discounts.max_percentage_cap', 0.80);
        $this->app['config']->set('user_discounts.stacking_order', 'percentage');
        
        $p50 = Discount::create(['code' => 'P50', 'type' => 'percentage', 'value' => 0.50, 'is_active' => true]);
        $p40 = Discount::create(['code' => 'P40', 'type' => 'percentage', 'value' => 0.40, 'is_active' => true]);
        $f10 = Discount::create(['code' => 'F10', 'type' => 'fixed', 'value' => 10.00, 'is_active' => true]);
        
        $this->manager->assign($this->user, 'P50', 99);
        $this->manager->assign($this->user, 'P40', 99);
        $this->manager->assign($this->user, 'F10', 99);
        
        $initialPrice = 100.00;

        // P50 (50%): $50 off. Total %: 50%
        // P40 (40%): Only 30% more allowed (80% cap - 50% used). $100 * 0.30 = $30 off (calculated on original).
        // Current remaining value: $100 - $50 - $30 = $20.
        // F10 ($10 Fixed): $20 - $10 = $10 final.

        // Pass 'false' for $incrementUsage to not deal with capping/concurrency issues on this test.
        $result = $this->manager->apply($this->user, $initialPrice, false); 
        
        $this->assertEquals(10.00, $result['final_value']);
        $this->assertEquals(90.00, $result['discount_value']);
        
        // Asserting the details show the cap was applied
        $this->assertEquals(50.00, $result['applied_discounts'][0]['applied_reduction']); // P50 applied in full
        $this->assertEquals(30.00, $result['applied_discounts'][1]['applied_reduction']); // P40 capped to 30%
        $this->assertEquals(10.00, $result['applied_discounts'][2]['applied_reduction']); // F10 applied on $20 remaining
    }
}
<?php

namespace Database\Seeders;

use App\Models\RoundingRule;
use Illuminate\Database\Seeder;

class RoundingRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'code' => 'WITHOUT_ASSORT_5',
                'name' => 'Gyűjtő nélkül, 5-re',
                'include_assortments' => false,
                'rounding_multiple' => 5,
                'rounding_mode' => 'threshold',
                'round_up_from_remainder' => 3,
                'active' => true,
            ],
            [
                'code' => 'WITH_ASSORT_5',
                'name' => 'Gyűjtővel együtt, 5-re',
                'include_assortments' => true,
                'rounding_multiple' => 5,
                'rounding_mode' => 'threshold',
                'round_up_from_remainder' => 3,
                'active' => true,
            ],
            [
                'code' => 'WITHOUT_ASSORT_10',
                'name' => 'Gyűjtő nélkül, 10-re',
                'include_assortments' => false,
                'rounding_multiple' => 10,
                'rounding_mode' => 'threshold',
                'round_up_from_remainder' => 5,
                'active' => true,
            ],
            [
                'code' => 'WITH_ASSORT_10',
                'name' => 'Gyűjtővel együtt, 10-re',
                'include_assortments' => true,
                'rounding_multiple' => 10,
                'rounding_mode' => 'threshold',
                'round_up_from_remainder' => 5,
                'active' => true,
            ],
        ];

        foreach ($rules as $rule) {
            RoundingRule::updateOrCreate(
                ['code' => $rule['code']],
                $rule,
            );
        }
    }
}
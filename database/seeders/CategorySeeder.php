<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            'Packaging' => ['Corrugated boxes', 'Plastic packaging', 'Labels & printing', 'Stretch film & tapes'],
            'Metals & steel' => ['MS / structural steel', 'Stainless steel', 'Aluminium', 'Fasteners'],
            'Auto components' => ['Castings & forgings', 'Machined parts', 'Rubber parts', 'Sheet metal parts'],
            'Chemicals & pharma' => ['APIs & intermediates', 'Solvents', 'Excipients', 'Lab consumables'],
            'Construction material' => ['Cement', 'TMT bars', 'Sand & aggregates', 'Tiles & sanitary'],
            'MRO & spares' => ['Bearings', 'Electrical', 'Tools', 'Lubricants'],
            'Services' => ['Transport & logistics', 'Housekeeping', 'Security', 'Printing'],
            'Office & facility' => ['Stationery', 'IT hardware', 'Furniture', 'Pantry supplies'],
        ];

        $sort = 0;
        foreach ($tree as $parentName => $children) {
            $parent = Category::updateOrCreate(
                ['slug' => Str::slug($parentName)],
                ['name' => $parentName, 'parent_id' => null, 'sort' => $sort++],
            );

            foreach ($children as $i => $childName) {
                Category::updateOrCreate(
                    ['slug' => Str::slug($parentName.' '.$childName)],
                    ['name' => $childName, 'parent_id' => $parent->id, 'sort' => $i],
                );
            }
        }
    }
}

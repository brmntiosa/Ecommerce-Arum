<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Province;
use Illuminate\Database\Seeder;
use Kavist\RajaOngkir\Facades\RajaOngkir;

class LocationsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $daftarProvinsi = RajaOngkir::provinsi()->all();
        foreach ($daftarProvinsi as $provinceRow) {
            Province::create([
                'provinced_id' => $provinceRow['province_id'],
                'title' => $provinceRow['province'],
            ]);
            $daftarKota = Rajaongkir::kota()->dariProvinsi($provinceRow['province_id'])->get();
            foreach ($daftarKota as $CityRow) {
                City::create([
                    'provinced_id' => $provinceRow['province_id'],  // sudah perbaiki typo
                    'cities_id' => $CityRow['city_id'],            // sesuaikan ke 'cities_id'
                    'title' => $CityRow['city_name'],
                ]);
                
            }
            
        }

    }
}

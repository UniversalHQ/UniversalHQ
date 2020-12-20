<?php

namespace App\Jobs;

use App\Models\Map;
use App\Models\MapItem;
use App\Models\MapObject;
use App\Models\MapObjectUpdate;
use App\Models\MapTextItem;
use App\Models\War;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateMapObjectsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    const     IS_VICTORY_BASE = '1';
    const     IS_BUILD_SITE = '4';
    const     IS_SCORCHED = '10';
    const     IS_TOWN_CLAIMED = '20';

    const OBJECT_TYPES = [
        5  => 'StaticBase1',
        6  => 'StaticBase2',
        7  => 'StaticBase3',
        8  => 'ForwardBase1',
        9  => 'ForwardBase2',
        10 => 'ForwardBase3',
        11 => 'Hospital',
        12 => 'VehicleFactory',
        13 => 'Armory',
        14 => 'Supply Station',
        15 => 'Workshop',
        16 => 'Manufacturing Plant',
        17 => 'Refinery',
        18 => 'Shipyard',
        19 => 'Tech Center',
        20 => 'Salvage Field',
        21 => 'Component Field',
        22 => 'Fuel Field',
        23 => 'Sulfur Field',
        24 => 'World Map Tent',
        25 => 'Travel Tent',
        26 => 'Training Area',
        27 => 'Special Base',
        28 => 'Observation Tower',
        29 => 'Fort',
        30 => 'Troop Ship',
        32 => 'Sulfur Mine',
        33 => 'Storage Facility',
        34 => 'Factory',
        35 => 'Garrison Station',
        36 => 'Ammo Factory',
        37 => 'Rocket Site',
        38 => 'Salvage Mine',
        39 => 'Construction Yard',
        40 => 'Component Mine',
        41 => 'Oil Well',
        44 => 'Cursed Fort',
        45 => 'Relic Base 1',
        46 => 'Relic Base 2',
        47 => 'Relic Base 3',
        51 => 'Mass Production Factory',
        52 => 'Seaport',
        53 => 'CoastalGun',
        54 => 'SoulFactory',
    ];
    /**
     * @var \App\Models\Map
     */
    private $map;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Map $map)
    {
        $this->map = $map;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // 1.generate Objects if missing
        $mapItems = $this->map->mapItems()->whereNull('map_object_id')->get();
        if ($mapItems) {
            $this->assignMapObjects($mapItems);
        }
        // 2. update objects with mapItem data
        $this->map->load('mapItems.mapObject');

        $this->map->mapItems->each(function (MapItem $mapItem) {
            if ($mapItem->team_id != $mapItem->mapObject->team_id ||//Town Owner Changed
                $mapItem->icon_type != $mapItem->mapObject->icon_type ||//Town Level changed
                ($mapItem->flags & self::IS_BUILD_SITE) != $mapItem->mapObject->is_build_site ||//Object build status changed
                ($mapItem->flags & self::IS_VICTORY_BASE) != $mapItem->mapObject->is_victory_base ||//shouldn`t change...
                ($mapItem->flags & self::IS_SCORCHED) != $mapItem->mapObject->is_scorched//Got hit by a rocket :D
            ) {
                $mapItem->mapObject->update([
                    'team_id'         => $mapItem->team_id,
                    'icon_type'       => $mapItem->icon_type,
                    'object_type'     => self::OBJECT_TYPES[$mapItem->icon_type],
                    'is_scorched'     => $mapItem->flags & self::IS_SCORCHED,
                    'is_victory_base' => $mapItem->flags & self::IS_VICTORY_BASE,
                    'is_build_site'   => $mapItem->flags & self::IS_BUILD_SITE,
                ]);
                logger()->info('MapObject #' . $mapItem->mapObject->id . ' has been updated',
                    $mapItem->mapObject->getChanges());
                $updatedData = array_merge($mapItem->mapObject->getChanges(), [
                    'map_object_id'     => $mapItem->mapObject->id,
                    'dynamic_timestamp' => $this->map->dynamic_timestamp,
                ]);
                MapObjectUpdate::create($updatedData);
            }
        });
    }

    public function assignMapObjects(Collection $mapItems)
    {
        $war = War::orderBy('war_number', 'desc')->first();
        $mapItems->each(function (MapItem $mapItem) use ($war) {
            $matchingTextItem = $this->findMatchingTextItem($mapItem);

            $mapObect = MapObject::create([
                'map_id'          => $mapItem->map_id,
                'war_id'          => $war->id,
                'x'               => $mapItem->x,
                'y'               => $mapItem->y,
                'text'            => $matchingTextItem->text,
                'team_id'         => $mapItem->team_id,
                'icon_type'       => $mapItem->icon_type,
                'object_type'     => self::OBJECT_TYPES[$mapItem->icon_type],
                'is_scorched'     => $mapItem->flags & self::IS_SCORCHED,
                'is_victory_base' => $mapItem->flags & self::IS_VICTORY_BASE,
                'is_build_site'   => $mapItem->flags & self::IS_BUILD_SITE,
            ]);
            $mapItem->mapObject()->associate($mapObect)->save();
            $matchingTextItem->mapObject()->associate($mapObect)->save();
        });
    }

    /**
     *
     *
     * @param \App\Models\MapItem $mapItem
     *
     * @return array
     */
    private function findMatchingTextItem(MapItem $mapItem): object
    {
        $tDif = [];
        /** @var MapTextItem $textItem */
        $mapTextItems = $this->map->mapTextItems;
        foreach ($mapTextItems as $textItem) {
            $xDif = $mapItem->x - $textItem->x;
            $yDif = $mapItem->y - $textItem->y;
            $tDif[$textItem->id] = sqrt(pow($xDif, 2) + pow($yDif, 2));
        }
        asort($tDif);

        return MapTextItem::find(array_key_first($tDif));
    }
}

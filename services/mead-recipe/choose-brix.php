<?php
require __DIR__ . '/../../vendor/autoload.php';

return function (array $event) {

    $body = json_decode($event['body'] ?? '', true);

    if (empty($body['honey_kg'])) {
        return [
            'statusCode' => 400,
            'body' => json_encode([
                'error' => 'Missing required field: honey_kg'
            ])
        ];
    }

    try {

        $honeyKg = (float)$body['honey_kg'];
        $yeastType = $body['yeast_type'] ?? 'wine';

        // 80% de azúcar en la miel
        $sugarKg = $honeyKg * 0.80;

        $targets = [30, 28, 26, 24, 22, 20];

        $results = [];

        foreach ($targets as $brix) {
            $totalMustKg = $sugarKg / ($brix / 100);
            $waterKg = $totalMustKg - $honeyKg;

            $mustLiters = round($totalMustKg, 2);

            // Calcular levadura y nutrientes
            if ($yeastType === 'bread') {
                $yeastGrams = $mustLiters * 1;
                $nutrientGrams = $mustLiters * 1;
            } else {
                $yeastGrams = $mustLiters * 0.25;
                $nutrientGrams = $mustLiters * 0.3;
            }

            $results[] = [
                'target_brix' => $brix,
                'honey_kg' => round($honeyKg, 2),
                'water_liters' => round($waterKg, 2),
                'total_must_liters' => $mustLiters,
                'yeast_type' => $yeastType,
                'yeast_grams' => round($yeastGrams, 2),
                'nutrient_grams' => round($nutrientGrams, 2)
            ];
        }

        return [
            'statusCode' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'input_honey_kg' => $honeyKg,
                'yeast_type' => $yeastType,
                'must_calculations' => $results
            ])
        ];

    } catch (Exception $e) {

        return [
            'statusCode' => 500,
            'body' => json_encode([
                'error' => $e->getMessage()
            ])
        ];
    }
};
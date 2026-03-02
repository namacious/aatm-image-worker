<?php

use Aws\S3\S3Client;

// S3Client is now injected from worker.php — not constructed per job
function renderCard(
    array $job,
    PDO $pdo,
    S3Client $s3,
    Redis $redis,
    array $config
): void
{
    // --------------------------------------------------
    // 1️⃣ ATOMIC LOCK + RETRY GUARD
    // --------------------------------------------------
    $lock = $pdo->prepare("
        UPDATE cards
        SET card_status = 'processing',
            updated_at = NOW()
        WHERE id = ?
          AND card_status IN ('pending')
          AND retry_count < 3
    ");

    $lock->execute([$job['card_id']]);

    if ($lock->rowCount() === 0) {
        throw new RuntimeException("Card {$job['card_id']} lock not acquired — already processing, failed, or retry_count >= 3");
    }

    try {
        // --------------------------------------------------
        // 2️⃣ TEMPLATE RESOLUTION
        // --------------------------------------------------
        $template = preg_replace('/[^a-z0-9_-]/i', '', $job['template'] ?? 'orange');
        $baseTemplatePath = rtrim($config['assets']['templates_dir'], '/') . '/' . $template;

        if (!is_dir($baseTemplatePath)) {
            throw new RuntimeException("Invalid card template: {$template}");
        }

        // --------------------------------------------------
        // 3️⃣ LOAD BACKGROUND
        // --------------------------------------------------
        $backgroundFile = "{$baseTemplatePath}/background_optimized.png";
        if (!is_file($backgroundFile)) {
            throw new RuntimeException("Missing background for template: {$template}");
        }

        $img = new Imagick($backgroundFile);
        $img->setImageFormat('png');
        $img->stripImage();

        // --------------------------------------------------
        // 4️⃣ STATE OVERLAY
        // --------------------------------------------------
        $stateCode = strtoupper($job['state_code']);
        $stateFile = "{$baseTemplatePath}/states/{$stateCode}.png";

        if (is_file($stateFile)) {
            $state = new Imagick($stateFile);
            $state->stripImage();
            $img->compositeImage($state, Imagick::COMPOSITE_OVER, 130, 278);
            $state->destroy();
        }

        // --------------------------------------------------
        // 5️⃣ TYPOGRAPHY SETUP
        // --------------------------------------------------
        $fontDir     = "{$baseTemplatePath}/fonts";
        $fontRegular = "{$fontDir}/Down-Regular.otf";
        $fontBold    = "{$fontDir}/Down-Bold.otf";

        // Fallback to regular if bold doesn't exist
        if (!is_file($fontBold)) {
            $fontBold = $fontRegular;
        }

        if (!is_file($fontRegular)) {
            throw new RuntimeException("Missing font for template: {$template}");
        }

        // Font sizes from Figma
        $titleFontSize   = 60;
        $titleLineHeight = 54;  // Figma exact
        $yearFontSize    = 32;
        $stateFontSize   = 100;

        // Template styles
        $templateStyles = [
            'orange' => ['text' => 'black',   'year' => 'black',   'dash' => 'white'],
            'black'  => ['text' => '#FFF200',  'year' => '#FFF200', 'dash' => '#F39DFA'],
            'green'  => ['text' => 'black',    'year' => 'black',   'dash' => '#FFF200'],
        ];

        $style = $templateStyles[$template] ?? $templateStyles['orange'];

        // --------------------------------------------------
        // Measure draws (for calculating text widths)
        // --------------------------------------------------
        $titleMeasure = new ImagickDraw();
        $titleMeasure->setFont($fontRegular);
        $titleMeasure->setFontSize($titleFontSize);

        $yearMeasure = new ImagickDraw();
        $yearMeasure->setFont($fontRegular);
        $yearMeasure->setFontSize($yearFontSize);

        // --------------------------------------------------
        // Render draws (for actually drawing text)
        // --------------------------------------------------
        $titleDraw = new ImagickDraw();
        $titleDraw->setFont($fontRegular);
        $titleDraw->setFontSize($titleFontSize);
        $titleDraw->setFillColor(new ImagickPixel($style['text']));
        $titleDraw->setTextAntialias(true);

        $yearDraw = new ImagickDraw();
        $yearDraw->setFont($fontRegular);
        $yearDraw->setFontSize($yearFontSize);
        $yearDraw->setFillColor(new ImagickPixel($style['year']));
        $yearDraw->setTextAntialias(true);

        // --------------------------------------------------
        // 7️⃣ MOVIE LIST RENDERING
        // Figma coords: title x=140, year x=855 (width=140), list y=486
        // --------------------------------------------------
        $titleX   = 140;
        $yearX    = 855;
        $yearW    = 140;
        $top      = 486;
        $maxWidth = 680; // 855 - 140 - 35 padding

        $paddingTop    = 40; // space above title text
        $paddingBottom = 40; // space below title text (before divider)
        $dividerGap    = 40; // space between divider and next movie

        foreach (array_slice($job['movies'], 0, 3) as $i => $movie) {
            $title = $movie['movie_title'] ?? '';
            $year  = $movie['release_year'] ?? null;

            // Word-wrap title
            $lines = [];
            $line  = '';

            foreach (explode(' ', $title) as $word) {
                $test = trim("$line $word");
                $w    = $img->queryFontMetrics($titleMeasure, $test)['textWidth'];

                if ($w > $maxWidth && $line) {
                    $lines[] = $line;
                    $line    = $word;
                } else {
                    $line = $test;
                }
            }
            if ($line) {
                $lines[] = $line;
            }

            // Baseline accounts for padding above
            $baseline = $top + $paddingTop + (int)($titleFontSize * 0.85);

            // Render title lines
            foreach ($lines as $j => $ln) {
                $img->annotateImage(
                    $titleDraw,
                    $titleX,
                    $baseline + $j * $titleLineHeight,
                    0,
                    $ln
                );
            }

            // Render year — right-aligned within year column
            if ($year) {
                $yearStr  = "({$year})";
                $ym       = $img->queryFontMetrics($yearMeasure, $yearStr);
                //$yearXPos = ($yearX + $yearW) - $ym['textWidth'];
                $yearXPos = 954 - $ym['textWidth']; // ← right edge = 954
                $img->annotateImage($yearDraw, $yearXPos, $baseline, 0, $yearStr);
            }

            // Total height of this row content (padding + lines)
            $contentHeight = $paddingTop + count($lines) * $titleLineHeight + $paddingBottom;

            // Draw divider at bottom of content (not after last movie)
            if ($i < 2) {
                $dividerY = $top + $contentHeight;

                $dash = new ImagickDraw();
                $dash->setStrokeColor(new ImagickPixel($style['dash']));
                $dash->setStrokeOpacity(0.70);
                $dash->setStrokeWidth(4);
                $dash->setStrokeDashArray([8, 12]);
                $dash->setFillColor(new ImagickPixel('none'));
                $dash->line($titleX, $dividerY, 954, $dividerY);
                $img->drawImage($dash);

                // Next movie starts after divider + gap
                $top += $contentHeight + $dividerGap;
            } else {
                $top += $contentHeight;
            }
        }

        // --------------------------------------------------
        // 8️⃣ SAVE TEMP FILE
        // --------------------------------------------------
        $img->stripImage();
        $img->setImageCompressionQuality(85);
        $tmp = tempnam(sys_get_temp_dir(), 'card_') . '.png';
        $img->writeImage($tmp);
        $img->destroy();

        // --------------------------------------------------
        // 9️⃣ UPLOAD TO CLOUDFLARE R2
        // $s3 is injected — no new S3Client() here
        // --------------------------------------------------
        $key = sprintf(
            'cards/%d/%d/%s.png',
            $job['user_id'],
            $job['state_id'],
            $job['card_hash']
        );

        $s3->putObject([
            'Bucket'      => $config['r2']['bucket'],
            'Key'         => $key,
            'Body'        => fopen($tmp, 'rb'),
            'ACL'         => 'public-read',
            'ContentType' => 'image/png',
        ]);

        unlink($tmp);

        $url = rtrim($config['r2']['publicUrl'], '/') . '/' . $key;

        // --------------------------------------------------
        // 🔟 FINAL DB UPDATE — SUCCESS
        // --------------------------------------------------
        $pdo->prepare("
            UPDATE cards
            SET card_status = 'ready',
                card_url     = ?,
                updated_at   = NOW()
            WHERE id = ?
        ")->execute([$url, $job['card_id']]);

        // --------------------------------------------------
        // 🧹 CACHE UPDATE — overwrite stale "processing"
        // --------------------------------------------------
        $cacheKey = 'c:card:hash:' . $job['card_hash'];

        // Refresh Cache
        $redis->setex(
            'c:card:hash:' . $job['card_hash'],  // ← add c: prefix
            86400,
            json_encode([
                'card_status' => 'ready',
                'card_url'    => $url,
            ])
        );

    } catch (Throwable $e) {

        // --------------------------------------------------
        // 🔁 FAILURE — INCREMENT RETRY
        // card_status → 'pending' if retry_count < 3 (requeue-cron will re-push)
        // card_status → 'failed'  if retry_count >= 3 (no more retries)
        // --------------------------------------------------
        $pdo->prepare("
            UPDATE cards
            SET retry_count = retry_count + 1,
                last_error  = ?,
                card_status = IF(retry_count + 1 >= 3, 'failed', 'pending'),
                updated_at  = NOW()
            WHERE id = ?
        ")->execute([
            substr($e->getMessage(), 0, 4000),
            $job['card_id']
        ]);

        throw $e; // ← rethrow AFTER DB update so worker.php logs it correctly
        // Do NOT rethrow — worker.php catch loop will log and continue
    }
}
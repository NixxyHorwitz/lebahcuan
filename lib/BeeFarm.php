<?php
declare(strict_types=1);

/**
 * BeeFarm Helper & Logic Service
 * Sistem Peternakan Lebah Cuan
 */

class BeeFarm
{
    /**
     * Hitung madu yang belum dipanen di suatu kandang milik user
     */
    public static function getHiveDetails(PDO $pdo, int $hive_id, int $user_id): ?array
    {
        $stmt = $pdo->prepare("
            SELECT h.*, m.name as master_name, m.description as master_desc, m.image as master_image,
                   m.max_slots, m.bonus_speed_pct, m.duration_days
            FROM user_bee_hives h
            JOIN bee_hives_master m ON m.id = h.hive_master_id
            WHERE h.id = ? AND h.user_id = ? AND h.is_active = 1
        ");
        $stmt->execute([$hive_id, $user_id]);
        $hive = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hive) return null;

        // Ambil semua lebah aktif di kandang ini
        $beeStmt = $pdo->prepare("
            SELECT b.*, t.name as type_name, t.description as type_desc, t.image as type_image,
                   t.honey_per_hour
            FROM user_bees b
            JOIN bee_types_master t ON t.id = b.bee_type_id
            WHERE b.hive_id = ? AND b.user_id = ? AND b.is_active = 1
              AND (b.expires_at IS NULL OR b.expires_at > NOW())
            ORDER BY b.id ASC
        ");
        $beeStmt->execute([$hive_id, $user_id]);
        $bees = $beeStmt->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        $total_honey = 0.0;
        $total_hourly_rate = 0.0;
        $bonusMultiplier = 1.0 + ((float)($hive['bonus_speed_pct'] ?? 0) / 100.0);

        foreach ($bees as &$bee) {
            $lastHarvest = strtotime((string)$bee['last_harvest_at']);
            $secondsElapsed = max(0, $now - $lastHarvest);
            $hoursElapsed = $secondsElapsed / 3600.0;
            $effectiveRate = (float)$bee['honey_per_hour'] * $bonusMultiplier;

            $beeHoney = $hoursElapsed * $effectiveRate;
            $bee['effective_rate'] = round($effectiveRate, 2);
            $bee['accumulated_honey'] = round($beeHoney, 2);
            $bee['seconds_elapsed'] = $secondsElapsed;

            $total_honey += $beeHoney;
            $total_hourly_rate += $effectiveRate;
        }
        unset($bee);

        // Kapasitas penampung madu per kandang = max_slots * 75 ml
        $maxCapacity = max(100.0, (float)$hive['max_slots'] * 75.0);
        $cappedHoney = min($total_honey, $maxCapacity);
        $fillPercentage = min(100.0, round(($cappedHoney / $maxCapacity) * 100.0, 1));

        return [
            'hive' => $hive,
            'bees' => $bees,
            'bee_count' => count($bees),
            'max_slots' => (int)$hive['max_slots'],
            'total_honey' => round($cappedHoney, 2),
            'raw_honey' => round($total_honey, 2),
            'max_capacity' => $maxCapacity,
            'fill_percentage' => $fillPercentage,
            'hourly_production' => round($total_hourly_rate, 2),
        ];
    }

    /**
     * Ambil lapak madu aktif milik user
     */
    public static function getUserActiveStall(PDO $pdo, int $user_id): ?array
    {
        $stmt = $pdo->prepare("
            SELECT s.*, m.tier_level, m.name as tier_name, m.description as tier_desc,
                   m.image as tier_image, m.sell_price_per_ml, m.daily_max_ml, m.duration_days
            FROM user_bee_stalls s
            JOIN bee_stalls_master m ON m.id = s.stall_master_id
            WHERE s.user_id = ? AND s.is_active = 1
              AND (s.expires_at IS NULL OR s.expires_at > NOW())
            ORDER BY s.id DESC LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $stall = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$stall) return null;

        // Reset harian jika hari sudah berganti
        $today = date('Y-m-d');
        if (empty($stall['last_sold_date']) || $stall['last_sold_date'] !== $today) {
            $pdo->prepare("UPDATE user_bee_stalls SET daily_sold_today = 0, last_sold_date = ? WHERE id = ?")
                ->execute([$today, $stall['id']]);
            $stall['daily_sold_today'] = 0;
            $stall['last_sold_date'] = $today;
        }

        $dailyMax = (float)$stall['daily_max_ml'];
        $dailySold = (float)$stall['daily_sold_today'];
        $stall['daily_remaining'] = max(0.0, round($dailyMax - $dailySold, 2));
        $stall['quota_percentage'] = $dailyMax > 0 ? min(100.0, round(($dailySold / $dailyMax) * 100.0, 1)) : 0.0;

        return $stall;
    }

    /**
     * Panen madu dari kandang
     */
    public static function harvestHive(PDO $pdo, int $user_id, int $hive_id): array
    {
        $pdo->beginTransaction();
        try {
            // Lock user row
            $uStmt = $pdo->prepare("SELECT id, honey_stock FROM users WHERE id = ? FOR UPDATE");
            $uStmt->execute([$user_id]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Pengguna tidak ditemukan.'];
            }

            $details = self::getHiveDetails($pdo, $hive_id, $user_id);
            if (!$details) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Kandang tidak ditemukan atau tidak aktif.'];
            }

            $amountHarvest = (float)$details['total_honey'];
            if ($amountHarvest < 0.1) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Belum ada madu yang cukup untuk dipanen (minimal 0.1 ml).'];
            }

            // Tambahkan madu ke stok user
            $newStock = round((float)$user['honey_stock'] + $amountHarvest, 2);
            $updUser = $pdo->prepare("UPDATE users SET honey_stock = ? WHERE id = ?");
            $updUser->execute([$newStock, $user_id]);

            // Reset waktu panen semua lebah di kandang ini
            $updBees = $pdo->prepare("
                UPDATE user_bees SET last_harvest_at = NOW()
                WHERE hive_id = ? AND user_id = ? AND is_active = 1
            ");
            $updBees->execute([$hive_id, $user_id]);

            // Catat log panen
            $logStmt = $pdo->prepare("
                INSERT INTO bee_harvest_logs (user_id, hive_id, amount_ml, harvested_at)
                VALUES (?, ?, ?, NOW())
            ");
            $logStmt->execute([$user_id, $hive_id, $amountHarvest]);

            $pdo->commit();
            return [
                'ok' => true,
                'msg' => "Berhasil memanen " . number_format($amountHarvest, 2, ',', '.') . " ml madu segar!",
                'harvested_ml' => $amountHarvest,
                'new_honey_stock' => $newStock,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'msg' => 'Gagal memproses panen: ' . $e->getMessage()];
        }
    }

    /**
     * Jual madu melalui lapak aktif user
     */
    public static function sellHoney(PDO $pdo, int $user_id, float $amount_ml): array
    {
        if ($amount_ml <= 0) {
            return ['ok' => false, 'msg' => 'Jumlah madu yang ingin dijual tidak valid.'];
        }

        $pdo->beginTransaction();
        try {
            // Lock user row
            $uStmt = $pdo->prepare("SELECT id, honey_stock, balance_wd, total_earned FROM users WHERE id = ? FOR UPDATE");
            $uStmt->execute([$user_id]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Pengguna tidak ditemukan.'];
            }

            $stall = self::getUserActiveStall($pdo, $user_id);
            if (!$stall) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Kamu belum memiliki Lapak Madu yang aktif! Silakan sewa/beli lapak terlebih dahulu.'];
            }

            $currentStock = (float)$user['honey_stock'];
            if ($amount_ml > $currentStock) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Stok madu kamu tidak mencukupi (Tersedia: ' . number_format($currentStock, 2, ',', '.') . ' ml).'];
            }

            $dailyRemaining = (float)$stall['daily_remaining'];
            if ($amount_ml > $dailyRemaining) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Melebihi sisa kuota penjualan lapak hari ini (Sisa kuota: ' . number_format($dailyRemaining, 2, ',', '.') . ' ml).'];
            }

            $pricePerMl = (float)$stall['sell_price_per_ml'];
            $totalRevenue = round($amount_ml * $pricePerMl, 2);

            $newHoneyStock = round($currentStock - $amount_ml, 2);
            $newBalanceWd = round((float)$user['balance_wd'] + $totalRevenue, 2);
            $newTotalEarned = round((float)$user['total_earned'] + $totalRevenue, 2);

            // Update user balance & stock
            $updUser = $pdo->prepare("UPDATE users SET honey_stock = ?, balance_wd = ?, total_earned = ? WHERE id = ?");
            $updUser->execute([$newHoneyStock, $newBalanceWd, $newTotalEarned, $user_id]);

            // Update stall quota
            $newSoldToday = (float)$stall['daily_sold_today'] + $amount_ml;
            $updStall = $pdo->prepare("UPDATE user_bee_stalls SET daily_sold_today = ?, last_sold_date = CURDATE() WHERE id = ?");
            $updStall->execute([$newSoldToday, $stall['id']]);

            // Insert sales log
            $logStmt = $pdo->prepare("
                INSERT INTO bee_sales_logs (user_id, stall_id, amount_ml, price_per_ml, total_revenue, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $logStmt->execute([$user_id, $stall['id'], $amount_ml, $pricePerMl, $totalRevenue]);

            $pdo->commit();
            return [
                'ok' => true,
                'msg' => "Madu berhasil dijual! Saldo Rp " . number_format($totalRevenue, 0, ',', '.') . " masuk ke Saldo Penarikan.",
                'sold_ml' => $amount_ml,
                'revenue' => $totalRevenue,
                'new_honey_stock' => $newHoneyStock,
                'new_balance_wd' => $newBalanceWd,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'msg' => 'Gagal memproses penjualan madu: ' . $e->getMessage()];
        }
    }

    /**
     * Beli Kandang baru menggunakan balance_dep
     */
    public static function buyHive(PDO $pdo, int $user_id, int $hive_master_id): array
    {
        $pdo->beginTransaction();
        try {
            $uStmt = $pdo->prepare("SELECT id, balance_dep FROM users WHERE id = ? FOR UPDATE");
            $uStmt->execute([$user_id]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Pengguna tidak ditemukan.'];
            }

            $mStmt = $pdo->prepare("SELECT * FROM bee_hives_master WHERE id = ? AND is_active = 1");
            $mStmt->execute([$hive_master_id]);
            $master = $mStmt->fetch(PDO::FETCH_ASSOC);
            if (!$master) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Katalog kandang tidak ditemukan.'];
            }

            $price = (float)$master['price'];
            $balDep = (float)$user['balance_dep'];
            if ($balDep < $price) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Saldo deposit tidak mencukupi untuk membeli kandang ini.'];
            }

            // Potong saldo deposit
            $newBalDep = round($balDep - $price, 2);
            $pdo->prepare("UPDATE users SET balance_dep = ? WHERE id = ?")->execute([$newBalDep, $user_id]);

            // Hitung masa aktif kandang
            $durationDays = (int)($master['duration_days'] ?? 30);
            $insHive = $pdo->prepare("
                INSERT INTO user_bee_hives (user_id, hive_master_id, custom_name, created_at, expires_at, is_active)
                VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), 1)
            ");
            $insHive->execute([$user_id, $hive_master_id, $master['name'], $durationDays]);

            $pdo->commit();
            return [
                'ok' => true,
                'msg' => "Kandang {$master['name']} berhasil dibeli!",
                'new_balance_dep' => $newBalDep,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'msg' => 'Gagal membeli kandang: ' . $e->getMessage()];
        }
    }

    /**
     * Beli Lebah dan tempatkan ke kandang tujuan menggunakan balance_dep
     */
    public static function buyBee(PDO $pdo, int $user_id, int $bee_type_id, int $target_hive_id): array
    {
        $pdo->beginTransaction();
        try {
            $uStmt = $pdo->prepare("SELECT id, balance_dep FROM users WHERE id = ? FOR UPDATE");
            $uStmt->execute([$user_id]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Pengguna tidak ditemukan.'];
            }

            // Validasi kandang tujuan
            $hStmt = $pdo->prepare("
                SELECT h.*, m.max_slots, m.name as hive_name
                FROM user_bee_hives h
                JOIN bee_hives_master m ON m.id = h.hive_master_id
                WHERE h.id = ? AND h.user_id = ? AND h.is_active = 1
                  AND (h.expires_at IS NULL OR h.expires_at > NOW())
            ");
            $hStmt->execute([$target_hive_id, $user_id]);
            $hive = $hStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hive) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Kandang tujuan tidak ditemukan atau sudah kedaluwarsa.'];
            }

            // Cek slot kandang
            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM user_bees WHERE hive_id = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())");
            $cStmt->execute([$target_hive_id]);
            $currentBees = (int)$cStmt->fetchColumn();
            if ($currentBees >= (int)$hive['max_slots']) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => "Kandang {$hive['hive_name']} sudah penuh (Maksimal {$hive['max_slots']} lebah)."];
            }

            // Ambil master lebah
            $bStmt = $pdo->prepare("SELECT * FROM bee_types_master WHERE id = ? AND is_active = 1");
            $bStmt->execute([$bee_type_id]);
            $beeMaster = $bStmt->fetch(PDO::FETCH_ASSOC);
            if (!$beeMaster) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Jenis lebah tidak ditemukan.'];
            }

            $price = (float)$beeMaster['price'];
            $balDep = (float)$user['balance_dep'];
            if ($balDep < $price) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Saldo deposit tidak mencukupi untuk membeli lebah ini.'];
            }

            // Potong saldo deposit
            $newBalDep = round($balDep - $price, 2);
            $pdo->prepare("UPDATE users SET balance_dep = ? WHERE id = ?")->execute([$newBalDep, $user_id]);

            // Masukkan lebah ke kandang
            $durationDays = (int)($beeMaster['duration_days'] ?? 30);
            $insBee = $pdo->prepare("
                INSERT INTO user_bees (user_id, hive_id, bee_type_id, last_harvest_at, created_at, expires_at, is_active)
                VALUES (?, ?, ?, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), 1)
            ");
            $insBee->execute([$user_id, $target_hive_id, $bee_type_id, $durationDays]);

            $pdo->commit();
            return [
                'ok' => true,
                'msg' => "{$beeMaster['name']} berhasil dibeli dan dimasukkan ke {$hive['hive_name']}!",
                'new_balance_dep' => $newBalDep,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'msg' => 'Gagal membeli lebah: ' . $e->getMessage()];
        }
    }

    /**
     * Beli / Sewa Lapak Madu menggunakan balance_dep
     */
    public static function buyStall(PDO $pdo, int $user_id, int $stall_master_id): array
    {
        $pdo->beginTransaction();
        try {
            $uStmt = $pdo->prepare("SELECT id, balance_dep FROM users WHERE id = ? FOR UPDATE");
            $uStmt->execute([$user_id]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Pengguna tidak ditemukan.'];
            }

            $sStmt = $pdo->prepare("SELECT * FROM bee_stalls_master WHERE id = ? AND is_active = 1");
            $sStmt->execute([$stall_master_id]);
            $stallMaster = $sStmt->fetch(PDO::FETCH_ASSOC);
            if (!$stallMaster) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Katalog lapak tidak ditemukan.'];
            }

            $price = (float)$stallMaster['price'];
            $balDep = (float)$user['balance_dep'];
            if ($balDep < $price) {
                $pdo->rollBack();
                return ['ok' => false, 'msg' => 'Saldo deposit tidak mencukupi untuk menyewa/membeli lapak ini.'];
            }

            // Potong saldo deposit
            $newBalDep = round($balDep - $price, 2);
            $pdo->prepare("UPDATE users SET balance_dep = ? WHERE id = ?")->execute([$newBalDep, $user_id]);

            // Nonaktifkan lapak lama jika ada
            $pdo->prepare("UPDATE user_bee_stalls SET is_active = 0 WHERE user_id = ?")->execute([$user_id]);

            // Buat lapak baru
            $durationDays = (int)($stallMaster['duration_days'] ?? 30);
            $insStall = $pdo->prepare("
                INSERT INTO user_bee_stalls (user_id, stall_master_id, daily_sold_today, last_sold_date, created_at, expires_at, is_active)
                VALUES (?, ?, 0, CURDATE(), NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), 1)
            ");
            $insStall->execute([$user_id, $stall_master_id, $durationDays]);

            $pdo->commit();
            return [
                'ok' => true,
                'msg' => "Lapak {$stallMaster['name']} berhasil disewa/dibeli!",
                'new_balance_dep' => $newBalDep,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'msg' => 'Gagal membeli lapak: ' . $e->getMessage()];
        }
    }
}

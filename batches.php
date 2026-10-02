<?php
// batches.php - Multi-round procurement ("รอบการจัดซื้อ") for projects bought several times through the year.
//
// A project opts in once (projects.is_multi_round = 1); its existing tracking data becomes round 1.
// Every round runs the full procurement cycle of the project's method and records the actual PO amount, so the
// project shows how much of its annual budget is committed, reserved and still available.

const BATCH_STEP_STATUSES = [
    'pending' => 'ยังไม่ดำเนินการ',
    'in_progress' => 'กำลังดำเนินการ',
    'completed' => 'เสร็จสิ้น',
];

// Steps of one round, keyed by step_key, in order. Mirrors the single-round flows in get_project_tracking_progress().
function batch_step_definitions($procurement_method) {
    if ($procurement_method === 'เฉพาะเจาะจง') {
        return [
            'request' => 'ขออนุมัติซื้อ/จ้าง และแต่งตั้งคณะกรรมการ',
            'spec_detail' => 'จัดทำรายละเอียดพัสดุ',
            'negotiate' => 'เจรจาตกลงราคา (ใบเสนอราคา)',
            'order' => 'ทำสัญญา / ใบสั่งซื้อ/จ้าง',
            'delivery' => 'ส่งมอบพัสดุ',
            'inspect' => 'ตรวจรับพัสดุ',
            'payment' => 'เบิกจ่ายเงิน',
        ];
    }
    return [
        'tor' => 'จัดทำร่าง TOR / ร่างประกาศ',
        'request' => 'รายงานขอซื้อ/จ้าง',
        'committee' => 'แต่งตั้งคณะกรรมการ',
        'announce' => 'ประกาศเชิญชวน (e-Bidding)',
        'winner' => 'ประกาศผู้ชนะการเสนอราคา',
        'order' => 'ทำสัญญา / วางหลักประกัน',
        'delivery' => 'ส่งมอบพัสดุ',
        'inspect' => 'ตรวจรับพัสดุ',
        'payment' => 'เบิกจ่ายเงิน',
        'report' => 'บันทึกรายงานผลการพิจารณา',
    ];
}

// Amount a round takes out of the budget: the PO amount once known, otherwise the requested (reserved) amount
function batch_effective_amount(array $batch) {
    if ($batch['batch_status'] === 'cancelled') return 0.0;
    return $batch['po_amount'] !== null ? (float)$batch['po_amount'] : (float)$batch['request_amount'];
}

/**
 * Load the rounds of a project with their steps and the budget totals.
 */
function batch_summary(PDO $pdo, array $proj) {
    $steps_def = batch_step_definitions($proj['procurement_method']);

    $stmt = $pdo->prepare("SELECT * FROM project_batches WHERE project_id = ? ORDER BY batch_no ASC, id ASC");
    $stmt->execute([$proj['id']]);
    $batches = $stmt->fetchAll();

    $steps_by_batch = [];
    if ($batches) {
        $ids = array_column($batches, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s_stmt = $pdo->prepare("SELECT * FROM project_batch_steps WHERE batch_id IN ($in)");
        $s_stmt->execute($ids);
        foreach ($s_stmt->fetchAll() as $row) {
            $steps_by_batch[$row['batch_id']][$row['step_key']] = $row;
        }
    }

    $budget = (float)$proj['budget'];
    $po_total = 0.0;
    $reserved = 0.0;
    $active = 0;
    $completed = 0;
    $latest = null; // most recent completed step across active rounds

    foreach ($batches as &$b) {
        $b['steps'] = [];
        $done = 0;
        $current = null;
        foreach ($steps_def as $key => $label) {
            $row = $steps_by_batch[$b['id']][$key] ?? null;
            $status = $row['status'] ?? 'pending';
            $date = $row['step_date'] ?? null;
            $b['steps'][$key] = ['label' => $label, 'status' => $status, 'date' => $date];
            if ($status === 'completed') {
                $done++;
                if ($b['batch_status'] !== 'cancelled' && $date && ($latest === null || $date >= $latest['date'])) {
                    $latest = ['date' => $date, 'label' => 'รอบที่ ' . $b['batch_no'] . ': ' . $label];
                }
            } elseif ($current === null) {
                $current = $label;
            }
        }
        $b['steps_done'] = $done;
        $b['steps_total'] = count($steps_def);
        $b['current_step'] = $current;

        if ($b['batch_status'] !== 'cancelled') {
            $active++;
            if ($b['batch_status'] === 'completed') $completed++;
            if ($b['po_amount'] !== null) {
                $po_total += (float)$b['po_amount'];
            } else {
                $reserved += (float)$b['request_amount'];
            }
        }
    }
    unset($b);

    $remaining = $budget - $po_total - $reserved;
    $auto_closed = $active > 0 && $completed === $active && $po_total >= $budget;

    return [
        'steps_def' => $steps_def,
        'batches' => $batches,
        'budget' => $budget,
        'po_total' => $po_total,
        'reserved' => $reserved,
        'remaining' => $remaining,
        'used_pct' => $budget > 0 ? ($po_total / $budget) * 100 : 0,
        'active_count' => $active,
        'completed_count' => $completed,
        'closed' => !empty($proj['closed_at']) || $auto_closed,
        'closed_reason' => !empty($proj['closed_at']) ? 'manual' : ($auto_closed ? 'budget' : null),
        'latest' => $latest,
    ];
}

/**
 * Progress for lists and reports, in the same shape as get_project_tracking_progress().
 */
function batch_progress(PDO $pdo, array $proj) {
    $sum = batch_summary($pdo, $proj);
    if ($sum['closed']) {
        $pct = 100;
    } else {
        // Share of the annual budget already ordered; 100% is reserved for closed projects
        $pct = min(99, (int)round($sum['used_pct']));
    }
    return [
        'progress_score' => $pct,
        'total_steps' => 100,
        'progress_pct' => $pct,
        'latest_completed_step' => $sum['latest']['label'] ?? '1. แผนการจัดซื้อจัดจ้าง',
        'latest_completed_date' => $sum['latest']['date'] ?? ($proj['plan_date'] ?? null),
        'current_status_color' => $sum['closed'] ? 'var(--success)' : 'var(--primary)',
        'is_multi_round' => true,
        'batch_summary' => $sum,
    ];
}

// Round status follows its steps unless it was cancelled
function batch_refresh_status(PDO $pdo, $batch_id, $procurement_method) {
    $def = batch_step_definitions($procurement_method);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_batch_steps WHERE batch_id = ? AND status = 'completed' AND step_key IN (" . implode(',', array_fill(0, count($def), '?')) . ")");
    $stmt->execute(array_merge([$batch_id], array_keys($def)));
    $status = ((int)$stmt->fetchColumn() === count($def)) ? 'completed' : 'in_progress';
    $pdo->prepare("UPDATE project_batches SET batch_status = ? WHERE id = ? AND batch_status <> 'cancelled'")->execute([$status, $batch_id]);
}

function batch_save_step(PDO $pdo, $batch_id, $key, $status, $date) {
    $pdo->prepare("INSERT INTO project_batch_steps (batch_id, step_key, status, step_date) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE status = VALUES(status), step_date = VALUES(step_date)")
        ->execute([$batch_id, $key, $status, $date]);
}

/**
 * Turn a single-round project into a multi-round one. Its existing tracking becomes round 1 (one-way).
 */
function batch_enable(PDO $pdo, array $proj) {
    if (!empty($proj['is_multi_round'])) return;
    $pid = (int)$proj['id'];

    $t = $pdo->prepare("SELECT * FROM project_tracking WHERE project_id = ?");
    $t->execute([$pid]);
    $track = $t->fetch() ?: [];

    $c = $pdo->prepare("SELECT company_name FROM project_contracts WHERE project_id = ? ORDER BY id");
    $c->execute([$pid]);
    $contractors = implode(', ', $c->fetchAll(PDO::FETCH_COLUMN));

    // Delivery / inspection / payment are complete only when every installment is
    $i = $pdo->prepare("SELECT COUNT(*) AS n,
            SUM(delivery_status = 'completed') AS d, MAX(delivery_date) AS dd,
            SUM(inspection_status = 'completed') AS i, MAX(inspection_date) AS id_,
            SUM(payment_status = 'completed') AS p, MAX(payment_date) AS pd
        FROM project_installments WHERE project_id = ?");
    $i->execute([$pid]);
    $inst = $i->fetch();
    $n = (int)$inst['n'];
    $from_installments = function ($done, $date) use ($n) {
        if ($n === 0) return ['pending', null];
        if ((int)$done === $n) return ['completed', $date];
        return [(int)$done > 0 ? 'in_progress' : 'pending', null];
    };
    $from_track = function ($prefix) use ($track) {
        $status = $track[$prefix . '_status'] ?? 'pending';
        return [in_array($status, array_keys(BATCH_STEP_STATUSES), true) ? $status : 'pending', $track[$prefix . '_date'] ?? null];
    };

    if ($proj['procurement_method'] === 'เฉพาะเจาะจง') {
        $steps = [
            'request' => $from_track('spec_step2'),
            'spec_detail' => $from_track('spec_step2b'),
            'negotiate' => $from_track('spec_step3'),
            'order' => $from_track('spec_step4'),
        ];
    } else {
        $a = $pdo->prepare("SELECT category_id, MAX(announce_date) FROM announcements WHERE project_id = ? GROUP BY category_id");
        $a->execute([$pid]);
        $ann = $a->fetchAll(PDO::FETCH_KEY_PAIR);
        $steps = [
            'tor' => $from_track('step2'),
            'request' => $from_track('step3'),
            'committee' => $from_track('step4'),
            'announce' => isset($ann[3]) ? ['completed', $ann[3]] : ['pending', null],
            'winner' => isset($ann[5]) ? ['completed', $ann[5]] : ['pending', null],
            'order' => $from_track('step7'),
            'report' => $from_track('step9'),
        ];
    }
    $steps['delivery'] = $from_installments($inst['d'], $inst['dd']);
    $steps['inspect'] = $from_installments($inst['i'], $inst['id_']);
    $steps['payment'] = $from_installments($inst['p'], $inst['pd']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO project_batches (project_id, batch_no, batch_name, request_amount, contractor_name, note) VALUES (?, 1, ?, 0, ?, ?)")
            ->execute([$pid, 'รอบที่ 1', $contractors !== '' ? $contractors : null, 'สร้างจากข้อมูลการติดตามเดิมของโครงการ กรุณาระบุวงเงินและยอดตามใบสั่งซื้อ']);
        $batch_id = (int)$pdo->lastInsertId();
        foreach (batch_step_definitions($proj['procurement_method']) as $key => $label) {
            [$status, $date] = $steps[$key] ?? ['pending', null];
            batch_save_step($pdo, $batch_id, $key, $status, $date ?: null);
        }
        batch_refresh_status($pdo, $batch_id, $proj['procurement_method']);
        $pdo->prepare("UPDATE projects SET is_multi_round = 1 WHERE id = ?")->execute([$pid]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Parse a money field: '' => null, otherwise a non-negative number rounded to satang
function batch_money($value) {
    $value = str_replace([',', ' '], '', trim((string)$value));
    if ($value === '') return null;
    if (!is_numeric($value) || (float)$value < 0) return false;
    return round((float)$value, 2);
}

function batch_date($value) {
    $value = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

/**
 * Handle a batch_* / project_close / project_reopen POST action. Sets a flash message and redirects.
 * Callers have already verified CSRF and that the user may edit (admin/superadmin).
 */
function batch_handle_action(PDO $pdo, $action) {
    $project_id = intval($_POST['project_id'] ?? 0);
    $back = 'Location: admin.php?view=tracking_detail&id=' . $project_id;
    $p = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
    $p->execute([$project_id]);
    $proj = $p->fetch();
    if (!$proj) {
        header('Location: admin.php?view=tracking');
        exit();
    }

    $load_batch = function () use ($pdo, $project_id) {
        $s = $pdo->prepare("SELECT * FROM project_batches WHERE id = ? AND project_id = ?");
        $s->execute([intval($_POST['batch_id'] ?? 0), $project_id]);
        return $s->fetch();
    };
    // Budget the other active rounds already take (PO amount, or the requested amount until a PO exists)
    $others_total = function ($exclude_id) use ($pdo, $project_id) {
        $s = $pdo->prepare("SELECT * FROM project_batches WHERE project_id = ? AND id <> ?");
        $s->execute([$project_id, (int)$exclude_id]);
        return array_sum(array_map('batch_effective_amount', $s->fetchAll()));
    };
    $over_budget_message = function ($total) use ($proj) {
        return 'ยอดรวมทุกรอบ ' . number_format($total, 2) . ' บาท เกินงบประมาณโครงการ ' . number_format((float)$proj['budget'], 2)
            . ' บาท หากต้องการบันทึก ให้ติ๊ก "ยืนยันบันทึกแม้ยอดรวมเกินงบประมาณ" แล้วบันทึกอีกครั้ง';
    };

    try {
        if ($action === 'batch_enable') {
            batch_enable($pdo, $proj);
            $_SESSION['success_flash'] = 'เปลี่ยนเป็นการจัดซื้อหลายรอบแล้ว ข้อมูลการติดตามเดิมถูกย้ายเป็น "รอบที่ 1" กรุณาระบุวงเงินของรอบนี้';

        } elseif (empty($proj['is_multi_round'])) {
            $_SESSION['error_flash'] = 'โครงการนี้ยังไม่ได้เปิดใช้การจัดซื้อหลายรอบ';

        } elseif ($action === 'batch_add') {
            $name = trim($_POST['batch_name'] ?? '');
            $amount = batch_money($_POST['request_amount'] ?? '');
            if (!empty($proj['closed_at'])) {
                $_SESSION['error_flash'] = 'โครงการนี้ปิดแล้ว กรุณาเปิดโครงการอีกครั้งก่อนเพิ่มรอบการจัดซื้อ';
            } elseif ($name === '' || !$amount) {
                $_SESSION['error_flash'] = 'กรุณาระบุชื่อรอบและวงเงินที่ขออนุมัติ (มากกว่า 0 บาท)';
            } elseif (($total = $others_total(0) + $amount) > (float)$proj['budget'] && empty($_POST['confirm_over_budget'])) {
                $_SESSION['error_flash'] = $over_budget_message($total);
            } else {
                $pdo->beginTransaction();
                $next = $pdo->prepare("SELECT COALESCE(MAX(batch_no), 0) + 1 FROM project_batches WHERE project_id = ?");
                $next->execute([$project_id]);
                $pdo->prepare("INSERT INTO project_batches (project_id, batch_no, batch_name, request_amount, note) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$project_id, (int)$next->fetchColumn(), $name, $amount, trim($_POST['note'] ?? '') ?: null]);
                $batch_id = (int)$pdo->lastInsertId();
                foreach (array_keys(batch_step_definitions($proj['procurement_method'])) as $key) {
                    batch_save_step($pdo, $batch_id, $key, 'pending', null);
                }
                $pdo->commit();
                $_SESSION['success_flash'] = 'เพิ่มรอบการจัดซื้อแล้ว';
                $back .= '#batch-' . $batch_id;
            }

        } elseif ($action === 'batch_save') {
            $batch = $load_batch();
            $name = trim($_POST['batch_name'] ?? '');
            $request_amount = batch_money($_POST['request_amount'] ?? '');
            $po_amount = batch_money($_POST['po_amount'] ?? '');
            $def = batch_step_definitions($proj['procurement_method']);
            $posted = is_array($_POST['steps'] ?? null) ? $_POST['steps'] : [];

            $step_values = [];
            $missing_dates = [];
            foreach ($def as $key => $label) {
                $status = $posted[$key]['status'] ?? 'pending';
                $status = array_key_exists($status, BATCH_STEP_STATUSES) ? $status : 'pending';
                $date = batch_date($posted[$key]['date'] ?? '');
                if ($status === 'completed' && $date === null) $missing_dates[] = $label;
                $step_values[$key] = [$status, $date];
            }

            if (!$batch) {
                $_SESSION['error_flash'] = 'ไม่พบรอบการจัดซื้อนี้';
            } elseif ($name === '' || $request_amount === null || $request_amount === false || $po_amount === false) {
                $_SESSION['error_flash'] = 'กรุณาระบุชื่อรอบ วงเงินที่ขออนุมัติ และยอดตามใบสั่งซื้อเป็นตัวเลขที่ถูกต้อง';
            } elseif ($missing_dates) {
                $_SESSION['error_flash'] = 'กรุณาระบุวันที่ของขั้นตอนที่เสร็จสิ้นแล้ว: ' . implode(', ', $missing_dates);
            } elseif ($batch['batch_status'] !== 'cancelled'
                && ($total = $others_total($batch['id']) + ($po_amount !== null ? $po_amount : $request_amount)) > (float)$proj['budget']
                && empty($_POST['confirm_over_budget'])) {
                $_SESSION['error_flash'] = $over_budget_message($total);
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE project_batches SET batch_name = ?, request_amount = ?, po_amount = ?, po_number = ?, contractor_name = ?, note = ? WHERE id = ?")
                    ->execute([$name, $request_amount, $po_amount, trim($_POST['po_number'] ?? '') ?: null, trim($_POST['contractor_name'] ?? '') ?: null, trim($_POST['note'] ?? '') ?: null, $batch['id']]);
                foreach ($step_values as $key => [$status, $date]) {
                    batch_save_step($pdo, $batch['id'], $key, $status, $date);
                }
                batch_refresh_status($pdo, $batch['id'], $proj['procurement_method']);
                $pdo->commit();
                $_SESSION['success_flash'] = 'บันทึกรอบที่ ' . $batch['batch_no'] . ' แล้ว';
            }
            $back .= '#batch-' . intval($_POST['batch_id'] ?? 0);

        } elseif ($action === 'batch_cancel' || $action === 'batch_restore') {
            $batch = $load_batch();
            if ($batch) {
                if ($action === 'batch_cancel') {
                    $pdo->prepare("UPDATE project_batches SET batch_status = 'cancelled' WHERE id = ?")->execute([$batch['id']]);
                    $_SESSION['success_flash'] = 'ยกเลิกรอบที่ ' . $batch['batch_no'] . ' แล้ว (ไม่นับรวมในยอดใช้งบ)';
                } else {
                    $pdo->prepare("UPDATE project_batches SET batch_status = 'in_progress' WHERE id = ?")->execute([$batch['id']]);
                    batch_refresh_status($pdo, $batch['id'], $proj['procurement_method']);
                    $_SESSION['success_flash'] = 'นำรอบที่ ' . $batch['batch_no'] . ' กลับมาใช้งานแล้ว';
                }
            }

        } elseif ($action === 'batch_delete') {
            $batch = $load_batch();
            if ($batch) {
                $pdo->prepare("DELETE FROM project_batches WHERE id = ?")->execute([$batch['id']]);
                $_SESSION['success_flash'] = 'ลบรอบที่ ' . $batch['batch_no'] . ' แล้ว';
            }

        } elseif ($action === 'project_close') {
            $pdo->prepare("UPDATE projects SET closed_at = CURDATE() WHERE id = ?")->execute([$project_id]);
            $_SESSION['success_flash'] = 'ปิดโครงการแล้ว';

        } elseif ($action === 'project_reopen') {
            $pdo->prepare("UPDATE projects SET closed_at = NULL WHERE id = ?")->execute([$project_id]);
            $_SESSION['success_flash'] = 'เปิดโครงการอีกครั้งแล้ว';
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Batch action ' . $action . ' failed: ' . $e->getMessage());
        $_SESSION['error_flash'] = 'บันทึกข้อมูลไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';
    }

    header($back);
    exit();
}

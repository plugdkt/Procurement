<?php
// tracking_batches.php - Tracking page of a multi-round project (included from admin.php, view=tracking_detail).
// Expects: $pdo, $proj (projects row + plan_name, fiscal_year, plan_date), $proj_id, $admin_role.

$sum = batch_summary($pdo, $proj);
$can_edit = $admin_role !== 'executive';
$money = function ($v) { return number_format((float)$v, 2); };
$budget = $sum['budget'];
$bar = function ($amount) use ($budget) { return $budget > 0 ? max(0, min(100, $amount / $budget * 100)) : 0; };
$remaining_color = $sum['remaining'] < 0 ? 'var(--danger)' : ($budget > 0 && $sum['remaining'] < $budget * 0.1 ? '#b45309' : 'var(--success)');
$status_badge = [
    'completed' => ['เสร็จสิ้นครบวงจร', 'background:#dcfce7;color:#166534;'],
    'in_progress' => ['กำลังดำเนินการ', 'background:#dbeafe;color:#1e40af;'],
    'cancelled' => ['ยกเลิก', 'background:#f1f5f9;color:#64748b;'],
];
?>
<div style="display: flex; gap: 16px; margin-bottom: 24px;">
    <a href="admin.php?view=tracking" class="btn btn-secondary">&larr; กลับไปหน้ารวม</a>
</div>

<section class="card-table-wrap" style="margin-bottom: 24px;">
    <div class="card-table-header" style="flex-wrap: wrap; gap: 12px;">
        <div>
            <h2><?= htmlspecialchars($proj['project_name']) ?></h2>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 4px;">
                แผน: <?= htmlspecialchars($proj['plan_name']) ?> (ปี <?= $proj['fiscal_year'] ?>) &middot;
                วิธี: <?= htmlspecialchars($proj['procurement_method']) ?> &middot;
                ผู้รับผิดชอบ: <?= !empty($proj['responsible_person']) ? htmlspecialchars($proj['responsible_person']) : 'ไม่ระบุ' ?> &middot;
                <strong>จัดซื้อหลายรอบ</strong>
            </p>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
            <?php if ($sum['closed']): ?>
                <span style="font-size: 0.85rem; padding: 4px 12px; border-radius: 12px; background: #dcfce7; color: #166534; font-weight: 600;">
                    ปิดโครงการแล้ว<?= $sum['closed_reason'] === 'budget' ? ' (ใช้งบครบ ทุกรอบเสร็จสิ้น)' : ' (' . get_thai_date($proj['closed_at']) . ')' ?>
                </span>
            <?php endif; ?>
            <?php if ($can_edit): ?>
                <?php if (!empty($proj['closed_at'])): ?>
                    <form action="admin.php" method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="project_reopen">
                        <?= csrf_field() ?>
                        <input type="hidden" name="project_id" value="<?= $proj_id ?>">
                        <button type="submit" class="btn btn-secondary">เปิดโครงการอีกครั้ง</button>
                    </form>
                <?php else: ?>
                    <button type="button" class="btn btn-primary" onclick="document.getElementById('batch-add-form').style.display='block'; document.getElementById('batch-add-name').focus();">+ เพิ่มรอบการจัดซื้อ</button>
                    <form action="admin.php" method="POST" style="margin:0;" onsubmit="return confirm('ปิดโครงการนี้? จะไม่สามารถเพิ่มรอบการจัดซื้อได้จนกว่าจะเปิดโครงการอีกครั้ง');">
                        <input type="hidden" name="action" value="project_close">
                        <?= csrf_field() ?>
                        <input type="hidden" name="project_id" value="<?= $proj_id ?>">
                        <button type="submit" class="btn btn-secondary">ปิดโครงการ</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Budget summary -->
    <div style="padding: 20px 24px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 14px;">
            <div style="background: #f8fafc; border-radius: 8px; padding: 12px 14px;">
                <div style="font-size: 0.8rem; color: var(--text-muted);">งบประมาณตามแผน</div>
                <div style="font-size: 1.25rem; font-weight: 700;"><?= $money($budget) ?></div>
            </div>
            <div style="background: #f8fafc; border-radius: 8px; padding: 12px 14px;">
                <div style="font-size: 0.8rem; color: var(--text-muted);">ยอดตามใบสั่งซื้อสะสม</div>
                <div style="font-size: 1.25rem; font-weight: 700;"><?= $money($sum['po_total']) ?></div>
            </div>
            <div style="background: #f8fafc; border-radius: 8px; padding: 12px 14px;">
                <div style="font-size: 0.8rem; color: var(--text-muted);">วงเงินที่จองไว้ (รอออกใบสั่งซื้อ)</div>
                <div style="font-size: 1.25rem; font-weight: 700;"><?= $money($sum['reserved']) ?></div>
            </div>
            <div style="background: #f8fafc; border-radius: 8px; padding: 12px 14px;">
                <div style="font-size: 0.8rem; color: var(--text-muted);">งบประมาณคงเหลือ</div>
                <div style="font-size: 1.25rem; font-weight: 700; color: <?= $remaining_color ?>;"><?= $money($sum['remaining']) ?></div>
            </div>
            <div style="background: #f8fafc; border-radius: 8px; padding: 12px 14px;">
                <div style="font-size: 0.8rem; color: var(--text-muted);">รอบที่เสร็จสิ้นครบวงจร</div>
                <div style="font-size: 1.25rem; font-weight: 700;"><?= $sum['completed_count'] ?> / <?= $sum['active_count'] ?></div>
            </div>
        </div>
        <div style="height: 10px; border-radius: 5px; background: #e2e8f0; overflow: hidden; display: flex;" title="ใบสั่งซื้อ / จองไว้">
            <div style="width: <?= $bar($sum['po_total']) ?>%; background: var(--success);"></div>
            <div style="width: <?= $bar($sum['reserved']) ?>%; background: #93c5fd;"></div>
        </div>
        <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 6px;">
            ใช้งบตามใบสั่งซื้อแล้ว <?= number_format($sum['used_pct'], 1) ?>%
            <?php if ($sum['reserved'] > 0): ?> &middot; <span style="color:#1d4ed8;">สีฟ้า</span> = วงเงินที่ขออนุมัติแล้วแต่ยังไม่ออกใบสั่งซื้อ<?php endif; ?>
            <?php if ($sum['remaining'] < 0): ?> &middot; <strong style="color: var(--danger);">ยอดรวมเกินงบประมาณโครงการ</strong><?php endif; ?>
        </div>
    </div>
</section>

<?php if ($can_edit && empty($proj['closed_at'])): ?>
<!-- Add round -->
<section class="card-table-wrap" id="batch-add-form" style="margin-bottom: 24px; display: none;">
    <div class="card-table-header"><h2>เพิ่มรอบการจัดซื้อ</h2></div>
    <form action="admin.php" method="POST" style="padding: 20px 24px; display: grid; grid-template-columns: 2fr 1fr; gap: 12px 16px;">
        <input type="hidden" name="action" value="batch_add">
        <?= csrf_field() ?>
        <input type="hidden" name="project_id" value="<?= $proj_id ?>">
        <div class="form-group">
            <label for="batch-add-name">ชื่อรอบ / รายละเอียด</label>
            <input type="text" id="batch-add-name" name="batch_name" class="form-control" placeholder="เช่น ป้ายประชาสัมพันธ์งานสัปดาห์วิทยาศาสตร์" required>
        </div>
        <div class="form-group">
            <label>วงเงินที่ขออนุมัติ (บาท)</label>
            <input type="number" step="0.01" min="0.01" name="request_amount" class="form-control" required>
            <small style="color: var(--text-muted);">คงเหลือ <?= $money($sum['remaining']) ?> บาท</small>
        </div>
        <div class="form-group" style="grid-column: 1 / -1;">
            <label>หมายเหตุ</label>
            <input type="text" name="note" class="form-control">
        </div>
        <label style="grid-column: 1 / -1; font-size: 0.85rem; color: var(--text-muted);">
            <input type="checkbox" name="confirm_over_budget" value="1"> ยืนยันบันทึกแม้ยอดรวมเกินงบประมาณ
        </label>
        <div style="grid-column: 1 / -1; display: flex; gap: 8px; justify-content: flex-end;">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('batch-add-form').style.display='none';">ยกเลิก</button>
            <button type="submit" class="btn btn-primary">เพิ่มรอบ</button>
        </div>
    </form>
</section>
<?php endif; ?>

<!-- Rounds -->
<?php if (empty($sum['batches'])): ?>
    <section class="card-table-wrap" style="padding: 40px; text-align: center; color: var(--text-muted);">ยังไม่มีรอบการจัดซื้อ</section>
<?php endif; ?>
<?php foreach ($sum['batches'] as $b):
    [$badge_label, $badge_style] = $status_badge[$b['batch_status']] ?? $status_badge['in_progress'];
    $open = $b['batch_status'] === 'in_progress';
    $amount_label = $b['po_amount'] !== null ? 'ตามใบสั่งซื้อ ' . $money($b['po_amount']) : 'วงเงินขออนุมัติ ' . $money($b['request_amount']);
?>
<section class="card-table-wrap" id="batch-<?= $b['id'] ?>" style="margin-bottom: 14px;<?= $b['batch_status'] === 'cancelled' ? ' opacity: 0.75;' : '' ?>">
    <div class="card-table-header" style="cursor: pointer; flex-wrap: wrap; gap: 10px;" onclick="toggleBatch(<?= $b['id'] ?>)">
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <span id="batch-chevron-<?= $b['id'] ?>" style="display:inline-block; width: 14px;"><?= $open ? '&#9662;' : '&#9656;' ?></span>
            <h2 style="margin: 0;">รอบที่ <?= (int)$b['batch_no'] ?>: <?= htmlspecialchars($b['batch_name']) ?></h2>
            <span style="font-size: 0.8rem; color: var(--text-muted);">
                <?= $b['contractor_name'] ? htmlspecialchars($b['contractor_name']) . ' &middot; ' : '' ?>
                ขั้นตอน <?= $b['steps_done'] ?>/<?= $b['steps_total'] ?><?= $b['current_step'] && $b['batch_status'] === 'in_progress' ? ' &middot; ถัดไป: ' . htmlspecialchars($b['current_step']) : '' ?>
            </span>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 0.85rem; font-weight: 600;"><?= $amount_label ?></span>
            <span style="font-size: 0.8rem; padding: 3px 10px; border-radius: 12px; font-weight: 600; <?= $badge_style ?>"><?= $badge_label ?></span>
        </div>
    </div>
    <div id="batch-body-<?= $b['id'] ?>" style="display: <?= $open ? 'block' : 'none' ?>; padding: 20px 24px;">
        <form action="admin.php" method="POST">
            <input type="hidden" name="action" value="batch_save">
            <?= csrf_field() ?>
            <input type="hidden" name="project_id" value="<?= $proj_id ?>">
            <input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
            <fieldset <?= $can_edit ? '' : 'disabled' ?> style="border: 0; padding: 0; margin: 0;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 16px; margin-bottom: 18px;">
                <div class="form-group">
                    <label>ชื่อรอบ / รายละเอียด</label>
                    <input type="text" name="batch_name" class="form-control" value="<?= htmlspecialchars($b['batch_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label>วงเงินที่ขออนุมัติ (บาท)</label>
                    <input type="number" step="0.01" min="0" name="request_amount" class="form-control" value="<?= htmlspecialchars($b['request_amount']) ?>" required>
                </div>
                <div class="form-group">
                    <label>ผู้ขาย / คู่สัญญา</label>
                    <input type="text" name="contractor_name" class="form-control" value="<?= htmlspecialchars($b['contractor_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>เลขที่ใบสั่งซื้อ / สัญญา</label>
                    <input type="text" name="po_number" class="form-control" value="<?= htmlspecialchars($b['po_number'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>ยอดตามใบสั่งซื้อจริง (บาท)</label>
                    <input type="number" step="0.01" min="0" name="po_amount" class="form-control" value="<?= htmlspecialchars($b['po_amount'] ?? '') ?>" placeholder="กรอกเมื่อออกใบสั่งซื้อแล้ว">
                </div>
                <div class="form-group">
                    <label>หมายเหตุ</label>
                    <input type="text" name="note" class="form-control" value="<?= htmlspecialchars($b['note'] ?? '') ?>">
                </div>
            </div>

            <div class="table-responsive">
                <table class="table-admin" style="font-size: 0.9rem;">
                    <thead><tr><th style="width: 50px;">#</th><th>ขั้นตอน</th><th style="width: 200px;">สถานะ</th><th style="width: 180px;">วันที่</th></tr></thead>
                    <tbody>
                        <?php $i = 1; foreach ($b['steps'] as $key => $step): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($step['label']) ?></td>
                            <td>
                                <select name="steps[<?= $key ?>][status]" class="form-control" style="padding: 6px;">
                                    <?php foreach (BATCH_STEP_STATUSES as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $step['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="date" name="steps[<?= $key ?>][date]" class="form-control" style="padding: 6px;" value="<?= htmlspecialchars($step['date'] ?? '') ?>"></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </fieldset>

            <?php if ($can_edit): ?>
            <div style="display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-top: 16px;">
                <label style="font-size: 0.85rem; color: var(--text-muted);">
                    <input type="checkbox" name="confirm_over_budget" value="1"> ยืนยันบันทึกแม้ยอดรวมเกินงบประมาณ
                </label>
                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="submit" class="btn btn-secondary" form="batch-<?= $b['batch_status'] === 'cancelled' ? 'restore' : 'cancel' ?>-<?= $b['id'] ?>">
                        <?= $b['batch_status'] === 'cancelled' ? 'นำรอบนี้กลับมาใช้งาน' : 'ยกเลิกรอบนี้' ?>
                    </button>
                    <button type="submit" class="btn btn-danger" form="batch-delete-<?= $b['id'] ?>">ลบรอบ</button>
                    <button type="submit" class="btn btn-primary">บันทึกรอบที่ <?= (int)$b['batch_no'] ?></button>
                </div>
            </div>
            <?php endif; ?>
        </form>
        <?php if ($can_edit): ?>
            <?php foreach (['cancel' => 'ยกเลิกรอบนี้? ยอดเงินของรอบจะไม่ถูกนับรวมในการใช้งบประมาณ', 'restore' => 'นำรอบนี้กลับมาใช้งาน?', 'delete' => 'ลบรอบนี้และข้อมูลขั้นตอนทั้งหมดของรอบ? ไม่สามารถกู้คืนได้'] as $op => $question): ?>
                <form id="batch-<?= $op ?>-<?= $b['id'] ?>" action="admin.php" method="POST" style="display:none;" onsubmit="return confirm(<?= htmlspecialchars(json_encode($question, JSON_UNESCAPED_UNICODE)) ?>);">
                    <input type="hidden" name="action" value="batch_<?= $op ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="project_id" value="<?= $proj_id ?>">
                    <input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
<?php endforeach; ?>

<script>
function toggleBatch(id) {
    const body = document.getElementById('batch-body-' + id);
    const open = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    document.getElementById('batch-chevron-' + id).innerHTML = open ? '&#9656;' : '&#9662;';
}
// Open the round linked from the URL hash (after saving)
if (location.hash.startsWith('#batch-')) {
    const body = document.getElementById('batch-body-' + location.hash.slice(7));
    if (body && body.style.display === 'none') toggleBatch(location.hash.slice(7));
}
// Completing a step without a date fills in today
document.querySelectorAll('select[name$="[status]"]').forEach(function (sel) {
    sel.addEventListener('change', function () {
        const date = sel.closest('tr').querySelector('input[type=date]');
        if (sel.value === 'completed' && !date.value) { const d = new Date(); date.value = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    });
});
</script>

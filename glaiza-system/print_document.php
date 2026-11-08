<?php
// ============================================================
// print_document.php — Print document with editable fields
// overlaid on the uploaded form image template.
// All fields are pre-filled but editable before printing.
// ============================================================
session_start();
require_once 'includes/db.php';
requireAdminLogin();

$id = (int)($_GET['id'] ?? 0);
$docType = trim($_GET['doc_type'] ?? $_GET['document_type'] ?? '');

if (!$id && $docType) {
    $request = dbFetchOne("
        SELECT dr.*,
               r.first_name, r.last_name, r.barangay_id,
               r.street_address, r.zone_purok, r.house_number,
               r.date_of_birth, r.gender, r.years_of_residency, r.occupation,
               TIMESTAMPDIFF(YEAR, r.date_of_birth, CURDATE()) AS age,
               CONCAT(
                 COALESCE(r.house_number,''), ' ',
                 COALESCE(r.street_address,''), ', ',
                 COALESCE(r.zone_purok,'')
               ) AS full_address
        FROM document_requests dr
        JOIN residents r ON dr.resident_id = r.id
        WHERE dr.document_type = ?
        ORDER BY dr.created_at DESC
        LIMIT 1
    ", [$docType]);

    if (!$request) {
        $resident = dbFetchOne("
            SELECT id, first_name, last_name, barangay_id, street_address,
                   zone_purok, house_number, date_of_birth, gender,
                   years_of_residency, occupation
            FROM residents
            WHERE status <> 'Inactive'
            ORDER BY created_at DESC
            LIMIT 1
        ");

        if (!$resident) die('No resident request found for this document type.');

        $request = [
            'id' => 0,
            'request_number' => 'PREVIEW',
            'resident_id' => $resident['id'],
            'document_type' => $docType,
            'purpose' => 'Preview for Admin Editing',
            'first_name' => $resident['first_name'],
            'last_name' => $resident['last_name'],
            'barangay_id' => $resident['barangay_id'],
            'street_address' => $resident['street_address'],
            'zone_purok' => $resident['zone_purok'],
            'house_number' => $resident['house_number'],
            'date_of_birth' => $resident['date_of_birth'],
            'gender' => $resident['gender'],
            'years_of_residency' => $resident['years_of_residency'],
            'occupation' => $resident['occupation'],
            'full_address' => trim(($resident['house_number'] ?? '') . ' ' . ($resident['street_address'] ?? '') . ', ' . ($resident['zone_purok'] ?? '')),
            'age' => null,
        ];
    }
}

if (!$id && !$docType) die('Missing request ID or document type.');
if (!$id && $docType && !$request) die('Document request not found.');
if ($id) {
    $request = dbFetchOne("
        SELECT dr.*,
               r.first_name, r.last_name, r.barangay_id,
               r.street_address, r.zone_purok, r.house_number,
               r.date_of_birth, r.gender, r.years_of_residency, r.occupation,
               TIMESTAMPDIFF(YEAR, r.date_of_birth, CURDATE()) AS age,
               CONCAT(
                 COALESCE(r.house_number,''), ' ',
                 COALESCE(r.street_address,''), ', ',
                 COALESCE(r.zone_purok,'')
               ) AS full_address
        FROM document_requests dr
        JOIN residents r ON dr.resident_id = r.id
        WHERE dr.id = ?
    ", [$id]);
}

if (!$request) die('Document request not found.');

$template  = dbFetchOne(
    "SELECT * FROM document_templates WHERE document_type=?",
    [$request['document_type']]
);
$imagePath = $template['image_path'] ?? null;
$positions = ($template && $template['field_positions'])
    ? json_decode($template['field_positions'], true)
    : [];

// Default values for each field key
$fieldValues = [
    'full_name'          => trim($request['first_name'] . ' ' . $request['last_name']),
    'barangay_id'        => $request['barangay_id'] ?? '',
    'age'                => ($request['age'] ?? '') . ' years old',
    'gender'             => $request['gender'] ?? '',
    'address'            => trim($request['full_address'] ?? ''),
    'zone_purok'         => $request['zone_purok'] ?? '',
    'date_of_birth'      => $request['date_of_birth']
                              ? date('F j, Y', strtotime($request['date_of_birth']))
                              : '',
    'occupation'         => $request['occupation'] ?? '',
    'purpose'            => $request['purpose'] ?? '',
    'issue_date'         => date('F j, Y'),
    'request_number'     => $request['request_number'] ?? '',
    'years_of_residency' => ($request['years_of_residency'] ?? '0') . ' year(s)',
];

$siteName = getSiteName();

function resolveCustomTextValue(string $templateText, array $request, array $fieldValues): string {
    $residentName = trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''));
    $fullAddress = trim($request['full_address'] ?? '');
    $values = [
        '{full_name}'          => $residentName,
        '{resident_name}'     => $residentName,
        '{barangay_id}'       => $request['barangay_id'] ?? '',
        '{age}'               => (string)($request['age'] ?? ''),
        '{gender}'            => $request['gender'] ?? '',
        '{address}'           => $fullAddress,
        '{full_address}'      => $fullAddress,
        '{house_number}'      => $request['house_number'] ?? '',
        '{street_address}'    => $request['street_address'] ?? '',
        '{zone_purok}'       => $request['zone_purok'] ?? '',
        '{date_of_birth}'    => !empty($request['date_of_birth']) ? date('F j, Y', strtotime($request['date_of_birth'])) : '',
        '{occupation}'       => $request['occupation'] ?? '',
        '{purpose}'          => $request['purpose'] ?? '',
        '{issue_date}'       => date('F j, Y'),
        '{request_number}'   => $request['request_number'] ?? '',
        '{years_of_residency}' => ($request['years_of_residency'] ?? '0') . ' year(s)',
    ];

    $resolved = $templateText;
    foreach ($values as $placeholder => $replacement) {
        $resolved = str_replace($placeholder, $replacement, $resolved);
        $resolved = str_replace(str_replace(['{','}'], ['{{','}}'], $placeholder), $replacement, $resolved);
    }

    return trim($resolved);
}

logActivity("Printed #{$id} ({$request['document_type']})", 'Issuance');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Print — <?= htmlspecialchars($request['document_type']) ?></title>
  <style>
    @page { size: A4; margin: 0; }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body { background: #f3f4f6; font-family: sans-serif; }

    /* ── Top toolbar (hidden when printing) ── */
    .toolbar {
      position: fixed; top: 0; left: 0; right: 0; z-index: 100;
      background: #1f2937; color: #fff;
      padding: 10px 20px;
      display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    }
    .toolbar h4 { margin: 0; font-size: 14px; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .toolbar button {
      padding: 7px 16px; border: none; border-radius: 7px;
      cursor: pointer; font-size: 13px; font-weight: 600;
    }
    .btn-print { background: #10b981; color: #fff; }
    .btn-print:hover { background: #059669; }
    .btn-close { background: #374151; color: #fff; }
    .btn-close:hover { background: #4b5563; }

    /* ── Date picker in toolbar ── */
    .date-wrap { display: flex; align-items: center; gap: 8px; font-size: 13px; }
    .date-wrap label { color: #d1d5db; white-space: nowrap; }
    .date-wrap input[type=date] {
      padding: 5px 10px; border-radius: 6px; border: none;
      font-size: 13px; background: #374151; color: #fff;
    }

    /* ── Page canvas ── */
    .page-wrap { padding-top: 70px; display: flex; justify-content: center; }
    .doc-page {
      position: relative;
      width: 210mm;
      min-height: 297mm;
      background: #fff;
      box-shadow: 0 4px 24px rgba(0,0,0,0.15);
      margin: 20px 0 40px;
      overflow: hidden;
    }

    /* ── Template image ── */
    .doc-page img.template-bg {
      width: 100%; height: 100%;
      position: absolute; top: 0; left: 0;
      object-fit: fill; display: block;
      pointer-events: none;
    }

    /* ── Editable field overlays ── */
    .doc-field {
      position: absolute;
      z-index: 10;
      background: transparent;
      border: none;
      border-bottom: 1.5px dashed #3b82f6;
      outline: none;
      font-family: 'Times New Roman', serif;
      color: #111;
      min-width: 60px;
      max-width: 90%;
      padding: 0 2px;
      line-height: 1.2;
    }
    .doc-field:focus {
      border-bottom-color: #0066cc;
      background: rgba(219, 234, 254, 0.4);
      border-radius: 3px;
    }

    /* ── No template fallback ── */
    .no-template {
      padding: 80px 40px;
      text-align: center;
      color: #6b7280;
      font-size: 16px;
    }

    /* ── Print styles ── */
    @media print {
      body { background: #fff; }
      .toolbar { display: none !important; }
      .page-wrap { padding-top: 0; }
      .doc-page {
        width: 100%; margin: 0;
        box-shadow: none;
      }
      /* Hide border/dashes on printed fields — just show the text */
      .doc-field {
        border: none !important;
        background: transparent !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
    }
  </style>
</head>
<body>

<!-- ── Toolbar ─────────────────────────────────────────────── -->
<div class="toolbar">
  <h4>
    🖨️ <?= htmlspecialchars($request['document_type']) ?> —
    <?= htmlspecialchars($request['request_number']) ?> —
    <?= htmlspecialchars(trim($request['first_name'] . ' ' . $request['last_name'])) ?>
  </h4>

  <!-- Date picker: changes all issue_date fields at once -->
  <div class="date-wrap">
    <label>Issue Date:</label>
    <input type="date" id="globalDate" value="<?= date('Y-m-d') ?>"
           oninput="updateDate(this.value)">
  </div>

  <button class="btn-print" onclick="window.print()">🖨️ Print</button>
  <button class="btn-close" onclick="window.close()">✕ Close</button>
</div>

<!-- ── Document page ───────────────────────────────────────── -->
<div class="page-wrap">
  <div class="doc-page" id="docPage">

    <?php if ($imagePath && file_exists(__DIR__ . '/' . $imagePath)): ?>

      <!-- Background form image -->
      <img class="template-bg"
           src="<?= htmlspecialchars($imagePath) ?>?v=<?= filemtime(__DIR__.'/'.$imagePath) ?>"
           alt="Form template">

      <!-- Editable fields overlaid on the image -->
      <?php foreach ($positions as $field => $pos):
        $value = $fieldValues[$field] ?? '';
        $customValue = trim((string)($pos['default_value'] ?? ''));
        if ($customValue !== '') {
          $value = resolveCustomTextValue($customValue, $request, $fieldValues);
        } elseif (preg_match('/^custom_text_\d+$/', $field)) {
          $value = resolveCustomTextValue((string)($pos['default_value'] ?? ''), $request, $fieldValues);
          if ($value === '') {
            $value = 'Custom Text';
          }
        }
        $fs       = (int)($pos['font_size'] ?? 12);
        $bold     = !empty($pos['bold']) ? 'font-weight:bold;' : '';
        $top      = (float)$pos['top'];
        $left     = (float)$pos['left'];
        $isDate   = ($field === 'issue_date');
      ?>
      <input
        type="text"
        class="doc-field <?= $isDate ? 'issue-date-field' : '' ?>"
        data-field="<?= htmlspecialchars($field) ?>"
        value="<?= htmlspecialchars($value) ?>"
        style="top:<?= $top ?>%;left:<?= $left ?>%;font-size:<?= $fs ?>px;<?= $bold ?>"
        title="Click to edit"
        spellcheck="false"
      >
      <?php endforeach; ?>

    <?php else: ?>
      <div class="no-template">
        <p style="font-size:28px;margin-bottom:12px">⚠️</p>
        <p>No template image uploaded for <strong><?= htmlspecialchars($request['document_type']) ?></strong>.</p>
        <p style="margin-top:10px;font-size:14px">
          Go to <strong>Document Templates</strong> in the sidebar to upload a form image first.
        </p>
      </div>
    <?php endif; ?>

  </div>
</div>

<script>
// Update all issue_date fields when the date picker changes
function updateDate(val) {
  if (!val) return;
  const d = new Date(val + 'T00:00:00');
  const formatted = d.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
  document.querySelectorAll('.issue-date-field').forEach(el => {
    el.value = formatted;
  });
}

// Auto-resize inputs to fit content
document.querySelectorAll('.doc-field').forEach(input => {
  const resize = () => {
    input.style.width = Math.max(60, input.value.length * (parseInt(input.style.fontSize) || 12) * 0.62) + 'px';
  };
  resize();
  input.addEventListener('input', resize);
});

// Auto-open print dialog
window.addEventListener('load', () => setTimeout(() => window.print(), 400));
</script>
</body>
</html>
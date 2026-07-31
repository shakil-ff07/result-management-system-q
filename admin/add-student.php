<?php
include('auth.php');
require_admin_or_assistant();
include('../includes/db_config.php');

$message = "";
$error = "";

// Fetch classes for dropdown
$academic_year = $_GET['academic_year'] ?? null;
if ($academic_year) {
    $stmt = $conn->prepare("SELECT * FROM classes WHERE academic_year = ? ORDER BY LENGTH(class_name), class_name, section");
    $stmt->execute([$academic_year]);
} else {
    $stmt = $conn->query("SELECT * FROM classes ORDER BY LENGTH(class_name), class_name, section");
}
$classes = $stmt->fetchAll();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $roll = $_POST['roll_number'];
    $name = $_POST['name'];
    $father = $_POST['father_name'];
    $mother = $_POST['mother_name'];
    $mother = $_POST['mother_name'];
    $dob = $_POST['dob'];
    if (empty($dob)) {
        $error = "Date of Birth is required!";
    } else {
        $class_id = $_POST['class_id'];
        $group = $_POST['student_group'];
        $optional_subject_id = $_POST['optional_subject_id'] ?: null;
        $phone = trim($_POST['phone'] ?? '') ?: null;

        $class_stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
        $class_stmt->execute([$class_id]);
        $class_row = $class_stmt->fetch(PDO::FETCH_ASSOC);
        $class_name = strtolower(trim($class_row['class_name'] ?? ''));
        $is_lower_class = (strpos($class_name, 'class 6') !== false || strpos($class_name, 'class 7') !== false || strpos($class_name, 'class 8') !== false);

        if ($is_lower_class && empty($optional_subject_id)) {
            $default_stmt = $conn->prepare("SELECT s.id
                                            FROM subjects s
                                            JOIN class_subjects cs ON s.id = cs.subject_id
                                            WHERE cs.class_id = ?
                                              AND cs.student_group = 'None'
                                              AND s.subject_name = 'Agriculture Studies / Home Science'
                                            ORDER BY s.id ASC
                                            LIMIT 1");
            $default_stmt->execute([$class_id]);
            $default_optional_subject_id = $default_stmt->fetchColumn();

            if ($default_optional_subject_id) {
                $optional_subject_id = $default_optional_subject_id;
            }
        }

        try {
            $stmt = $conn->prepare("INSERT INTO students (roll_number, name, father_name, mother_name, dob, class_id, student_group, main_elective_id, optional_subject_id, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$roll, $name, $father, $mother, $dob, $class_id, $group, $_POST['main_elective_id'] ?: null, $optional_subject_id, $phone]);
            $message = "Student added successfully!";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000 && strpos($e->getMessage(), '1062') !== false) {
                $error = "This Roll already exists in this class. Please choose a different roll number.";
            } else {
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Add Student - SRMS Admin</title>
    <?php include('header.php'); ?>
</head>

<body>

    <?php include('sidebar.php'); ?>

    <div id="content">
        <?php include('topbar.php'); ?>
        <div class="container-fluid px-4">
            <!-- Header Section -->
            <div class="page-header">
                <div class="d-flex align-items-center">
                    <div class="header-icon-box shadow-sm">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div>
                        <h1 class="fw-bold mb-0">Add New Student</h1>
                        <p class="mb-0">Enroll a student into the system.</p>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="manage-students.php<?php echo $academic_year ? '?academic_year=' . urlencode($academic_year) : ''; ?>"
                        class="btn btn-sm btn-outline-secondary rounded-pill px-3 shadow-sm">
                        <i class="fas fa-arrow-left me-1"></i> Back to List
                    </a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form action="" method="POST" class="row g-4">
                        <!-- Section: Personal Information -->
                        <div class="col-12">
                            <h5 class="fw-bold mb-0 pb-2 border-bottom d-flex align-items-center gap-2" style="background: #111827; color: #ffffff; border-radius: 6px 6px 0 0; padding: 10px 12px; font-size: 0.95rem; letter-spacing: 0.5px;">
                                <i class="fas fa-user-circle text-white" style="font-size: 1.1rem;"></i>
                                PERSONAL INFORMATION
                            </h5>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="Enter student's full name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date of Birth <span class="text-danger">*</span></label>
                            <input type="date" name="dob" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Father's Name</label>
                            <input type="text" name="father_name" class="form-control" placeholder="Enter father's name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mother's Name</label>
                            <input type="text" name="mother_name" class="form-control" placeholder="Enter mother's name">
                        </div>

                        <!-- Section: Academic Details -->
                        <div class="col-12 mt-4">
                            <h5 class="fw-bold mb-0 pb-2 border-bottom d-flex align-items-center gap-2" style="background: #111827; color: #ffffff; border-radius: 6px 6px 0 0; padding: 10px 12px; font-size: 0.95rem; letter-spacing: 0.5px;">
                                <i class="fas fa-graduation-cap text-white" style="font-size: 1.1rem;"></i>
                                ACADEMIC DETAILS
                            </h5>
                        </div>

                        <?php
                        // Check for pre-selected class
                        $pre_selected_class_id = $_GET['class_id'] ?? null;
                        $pre_selected_class_name = $_GET['class_name'] ?? null;
                        $pre_selected_section = $_GET['section'] ?? null;
                        $pre_selected_year = $_GET['academic_year'] ?? null;

                        $is_pre_selected = $pre_selected_class_id && $pre_selected_class_name;

                        // Detect if the pre-selected section is a stream → auto-set group
                        $stream_group = null;
                        if ($pre_selected_section) {
                            $sec_lower = strtolower(trim($pre_selected_section));
                            if (preg_match('/(science)/i', $sec_lower))       $stream_group = 'Science';
                            elseif (preg_match('/(arts?)/i', $sec_lower))     $stream_group = 'Arts';
                            elseif (preg_match('/(commerce|comm)/i', $sec_lower)) $stream_group = 'Commerce';
                        }

                        // Pre-fetch last roll number for pre-selected class
                        $last_roll_info = null;
                        if ($pre_selected_class_id) {
                            $lr_stmt = $conn->prepare("SELECT MAX(roll_number) AS last_roll, COUNT(*) AS total FROM students WHERE class_id = ?");
                            $lr_stmt->execute([$pre_selected_class_id]);
                            $lr_row = $lr_stmt->fetch(PDO::FETCH_ASSOC);
                            $last_roll_info = [
                                'last_roll' => $lr_row['last_roll'] ? (int) $lr_row['last_roll'] : null,
                                'next_roll' => $lr_row['last_roll'] ? (int) $lr_row['last_roll'] + 1 : 1,
                                'count'     => (int) $lr_row['total'],
                            ];
                        }
                        ?>

                        <div class="col-md-4">
                            <label class="form-label">Class <span class="text-danger">*</span></label>
                            <?php if ($is_pre_selected): ?>
                                <div class="input-group">
                                    <input type="text" class="form-control bg-light"
                                        value="<?php echo $pre_selected_class_name . ' (' . $pre_selected_section . ') - ' . $pre_selected_year; ?>"
                                        readonly>
                                    <input type="hidden" name="class_id" id="class_id"
                                        value="<?php echo $pre_selected_class_id; ?>"
                                        data-class-name="<?php echo htmlspecialchars($pre_selected_class_name); ?>"
                                        data-section="<?php echo htmlspecialchars($pre_selected_section ?? ''); ?>">
                                    <a href="add-student.php?academic_year=<?php echo urlencode($pre_selected_year); ?>"
                                        class="btn btn-outline-secondary" title="Change Class">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                </div>
                            <?php else: ?>
                                <select name="class_id" id="class_id" class="form-select" required>
                                     <option value="">Choose...</option>
                                     <?php foreach ($classes as $c):
                                         $cn  = strtolower(trim($c['class_name']));
                                         $sec = trim($c['section']);
                                         // For Class 10 ONLY, skip plain letter sections (A, B, C…)
                                         // Class 10 is purely stream-based (Science, Arts, Commerce)
                                         // Class 9 keeps both letter sections (A, B) and stream sections
                                         $isClass10       = preg_match('/class\s*10/i', $cn);
                                         $isLetterSection = preg_match('/^[A-Za-z]$/', $sec);
                                         if ($isClass10 && $isLetterSection) continue;
                                     ?>
                                         <option value="<?php echo $c['id']; ?>" data-class-name="<?php echo htmlspecialchars($c['class_name']); ?>">
                                             <?php echo $c['class_name'] . " (" . $c['section'] . ")"; ?>
                                         </option>
                                     <?php endforeach; ?>
                                 </select>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Roll Number <span class="text-danger">*</span></label>
                            <input type="number" id="roll_number_input" name="roll_number" class="form-control" placeholder="Enter roll number" required>
                            <div id="last_roll_hint" class="mt-2" style="font-size: 0.8rem; <?php echo $last_roll_info ? '' : 'display:none;'; ?>">
                                <?php if ($last_roll_info): ?>
                                    <?php if ($last_roll_info['last_roll']): ?>
                                        <span style="background: rgba(15,23,42,0.06); border: 1px solid rgba(15,23,42,0.12); border-radius: 6px; padding: 4px 10px; color: #475569; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                            <i class="fas fa-info-circle" style="color: #94a3b8;"></i>
                                            Last roll: <strong><?php echo $last_roll_info['last_roll']; ?></strong>
                                            &nbsp;·&nbsp; Suggested next: <strong style="color: #0f172a;"><?php echo $last_roll_info['next_roll']; ?></strong>
                                        </span>
                                    <?php else: ?>
                                        <span style="background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.2); border-radius: 6px; padding: 4px 10px; color: #16a34a; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                            <i class="fas fa-star"></i> First student in this class
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="col-md-4" id="group_container" style="<?php echo (!$is_pre_selected || $stream_group) ? 'display:none;' : ''; ?>">
                            <label class="form-label">Group</label>
                            <select name="student_group" id="student_group" class="form-select">
                                <option value="None" <?php if (!$stream_group) echo 'selected'; ?>>None</option>
                                <option value="Science" <?php if ($stream_group === 'Science') echo 'selected'; ?>>Science</option>
                                <option value="Commerce" <?php if ($stream_group === 'Commerce') echo 'selected'; ?>>Commerce</option>
                                <option value="Arts" <?php if ($stream_group === 'Arts') echo 'selected'; ?>>Arts</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3" id="main_elective_container" style="display:none;">
                            <select name="main_elective_id" id="main_elective_id">
                                <option value="">Auto-calculating...</option>
                            </select>
                        </div>
                        <div class="col-md-4" id="optional_container" style="<?php echo ($is_pre_selected && $stream_group) ? '' : 'display:none;'; ?>">
                            <label class="form-label">Optional Subject</label>
                            <select name="optional_subject_id" id="optional_subject_id" class="form-select">
                                <option value="">None</option>
                            </select>
                        </div>

                        <!-- Section: Contact Details -->
                        <div class="col-12 mt-4">
                            <h5 class="fw-bold mb-0 pb-2 border-bottom d-flex align-items-center gap-2" style="background: #111827; color: #ffffff; border-radius: 6px 6px 0 0; padding: 10px 12px; font-size: 0.95rem; letter-spacing: 0.5px;">
                                <i class="fas fa-address-book text-white" style="font-size: 1.1rem;"></i>
                                CONTACT DETAILS
                            </h5>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="fas fa-phone me-1 text-muted"></i>Phone Number <span class="text-muted" style="font-size:0.8rem;">(Optional)</span></label>
                            <input type="tel" name="phone" class="form-control" placeholder="e.g. 017XXXXXXXX">
                        </div>

                        <div class="col-12 mt-5 pt-3 border-top d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary px-5 py-2 fw-bold" style="border-radius: 8px;">
                                <i class="fas fa-plus-circle me-2"></i>Add Student
                            </button>
                        </div>
                    </form>

                    <script>
                        // Basic dynamic lookup for optional subjects
                        // Trigger update immediately if class is pre-selected
                        const preSelectedClassId = "<?php echo $pre_selected_class_id; ?>";

                        document.getElementById('class_id')?.addEventListener('change', function() {
                            handleClassChange();
                            updateGroupFields('class');
                            fetchLastRoll();
                        });
                        document.getElementById('student_group').addEventListener('change', () => updateGroupFields('group'));
                        document.getElementById('optional_subject_id').addEventListener('change', function() {
                            autoAssignMainElective();
                        });

                        function autoAssignMainElective() {
                            const group = document.getElementById('student_group').value;
                            const optionalSelect = document.getElementById('optional_subject_id');
                            const optionalVal = optionalSelect.value;
                            const meSelect = document.getElementById('main_elective_id');
                            const classId = document.getElementById('class_id').value;

                            // Only try to auto-assign for Science/Arts when class is selected
                            if (!classId || !(group === 'Science' || group === 'Arts')) {
                                return;
                            }

                            // Fetch the available main elective options for this class/group
                            fetch(`get_optional_subjects.php?class_id=${classId}&group=${group}&type=main_elective`)
                                .then(res => res.json())
                                .then(data => {
                                    // data is an array of {id, subject_name}
                                    if (!Array.isArray(data) || data.length === 0) {
                                        // nothing to assign
                                        return;
                                    }

                                    // Attempt to map the selected optional's name to the main elective
                                    const optionalText = optionalVal ? optionalSelect.options[optionalSelect.selectedIndex].text.trim() : '';

                                    if (optionalText && data.length >= 1) {
                                        // If there are two selective subjects (swap pair), choose the other one
                                        if (data.length === 2) {
                                            const match = data.find(d => d.subject_name === optionalText);
                                            if (match) {
                                                const other = data.find(d => d.id != match.id);
                                                if (other) meSelect.value = other.id;
                                            } else {
                                                // No exact match — default to first
                                                meSelect.value = data[0].id;
                                            }
                                        } else {
                                            // Single selective subject — set it as main
                                            meSelect.value = data[0].id;
                                        }
                                    } else {
                                        // No optional selected — default main to first option
                                        meSelect.value = data[0].id;
                                    }
                                    // Keep main elective non-required and hidden; value is auto-filled so server receives it.
                                }).catch(() => {
                                    // ignore errors — don't block form
                                });
                        }

                        function getClassName() {
                            const classInput = document.getElementById('class_id');
                            const selectedOption = classInput?.tagName === 'SELECT'
                                ? classInput.options[classInput.selectedIndex]
                                : null;

                            if (selectedOption) {
                                return (selectedOption.getAttribute('data-class-name') || '').trim().toLowerCase();
                            }

                            return (classInput?.getAttribute('data-class-name') || '').trim().toLowerCase();
                        }

                        // Returns the section string for stream detection.
                        // For a pre-selected hidden input: reads data-section attribute.
                        // For a live SELECT: extracts the section from the option text e.g. "Class 9 (Arts)" -> "arts"
                        function getSection() {
                            const classInput = document.getElementById('class_id');
                            if (!classInput) return '';

                            if (classInput.tagName === 'SELECT') {
                                const text = classInput.options[classInput.selectedIndex]?.text || '';
                                // Extract content inside parentheses e.g. "Class 9 (Arts)" -> "arts"
                                const match = text.match(/\(([^)]+)\)/);
                                return match ? match[1].trim().toLowerCase() : text.toLowerCase();
                            }

                            // Hidden input (pre-selected via URL) — read data-section
                            return (classInput.getAttribute('data-section') || '').trim().toLowerCase();
                        }

                        function handleClassChange() {
                            const className = getClassName();
                            const section   = getSection();
                            const groupContainer    = document.getElementById('group_container');
                            const groupSelect       = document.getElementById('student_group');
                            const optionalContainer = document.getElementById('optional_container');
                            const optionalSelect    = document.getElementById('optional_subject_id');

                            // No class selected — reset everything hidden
                            if (!className) {
                                groupContainer.style.display    = 'none';
                                optionalContainer.style.display = 'none';
                                groupSelect.value    = 'None';
                                optionalSelect.value = '';
                                return;
                            }

                            // Class 6, 7, 8 — no group needed, optional subject shown normally
                            const isLowerClass = ['class 6', 'class 7', 'class 8']
                                .some(name => className.includes(name));

                            if (isLowerClass) {
                                groupContainer.style.display    = 'none';
                                optionalContainer.style.display = 'block';
                                groupSelect.value = 'None';
                                return;
                            }

                            // Class 9 / 10 — detect stream from section
                            const scienceMatch  = /science/.test(section);
                            const artsMatch     = /arts?/.test(section);
                            const commerceMatch = /commerce|comm/.test(section);
                            const isStreamSection = scienceMatch || artsMatch || commerceMatch;

                            if (isStreamSection) {
                                // Stream section — auto-set group, hide group picker, show optional subject
                                if (scienceMatch)       groupSelect.value = 'Science';
                                else if (artsMatch)     groupSelect.value = 'Arts';
                                else if (commerceMatch) groupSelect.value = 'Commerce';
                                groupContainer.style.display    = 'none';
                                optionalContainer.style.display = 'block';
                            } else {
                                // Class 9 (A/B) — unassigned, will be grouped later
                                groupContainer.style.display    = 'none';
                                optionalContainer.style.display = 'none';
                                groupSelect.value    = 'None';
                                optionalSelect.value = '';
                            }
                        }

                        function updateGroupFields(source = 'all') {
                            const classId   = document.getElementById('class_id').value;
                            const group     = document.getElementById('student_group').value;
                            const className = getClassName();
                            const section   = getSection();
                            if (!classId) return;

                            // Class 9 letter section (A/B) — skip all subject fetching
                            const isClass9       = /class 9/.test(className);
                            const isStreamSec    = /science|arts?|commerce|comm/.test(section);
                            const isClass9Unassigned = isClass9 && !isStreamSec;

                            if (isClass9Unassigned) {
                                // Student will be assigned a group later — nothing to load
                                document.getElementById('optional_subject_id').innerHTML = '<option value="">None</option>';
                                document.getElementById('main_elective_id').value = '';
                                document.getElementById('main_elective_container').style.display = 'none';
                                return;
                            }

                            // Fetch Optional Subjects
                            fetch(`get_optional_subjects.php?class_id=${classId}&group=${group}&type=optional`)
                                .then(res => res.json())
                                .then(data => {
                                    const select = document.getElementById('optional_subject_id');
                                    const currentVal = select.value;
                                    const mainVal = document.getElementById('main_elective_id').value;
                                    const selectedClassName = getClassName();
                                    const isLowerClass = ['class 6', 'class 7', 'class 8'].some(name => selectedClassName.includes(name));
                                    const defaultOptional = data.find(sub => sub.subject_name === 'Agriculture Studies / Home Science');

                                    select.innerHTML = '<option value="">None</option>';
                                    data.forEach(sub => {
                                        if (sub.id != mainVal) {
                                            const isDefaultSelection = isLowerClass && !currentVal && sub.id == defaultOptional?.id;
                                            const selected = (sub.id == currentVal) || isDefaultSelection ? 'selected' : '';
                                            select.innerHTML += `<option value="${sub.id}" ${selected}>${sub.subject_name}</option>`;
                                        }
                                    });

                                    if (isLowerClass && !currentVal && defaultOptional?.id) {
                                        select.value = String(defaultOptional.id);
                                    }
                                });

                            // Only Fetch Main Elective if group/class changed
                            if (source === 'all' || source === 'group' || source === 'class') {
                                const meContainer = document.getElementById('main_elective_container');
                                const meSelect = document.getElementById('main_elective_id');

                                const selectedClassName = getClassName();
                                const isClass9or10 = !['class 6', 'class 7', 'class 8'].some(name => selectedClassName.includes(name));

                                if ((group === 'Science' || group === 'Arts') && isClass9or10) {
                                    fetch(`get_optional_subjects.php?class_id=${classId}&group=${group}&type=main_elective`)
                                        .then(res => res.json())
                                        .then(data => {
                                            const meContainer = document.getElementById('main_elective_container');
                                            if (data.length > 0) {
                                                const currentMe = meSelect.value;
                                                meSelect.innerHTML = '<option value="">Select Main...</option>';
                                                data.forEach(sub => {
                                                    const selected = (sub.id == currentMe) ? 'selected' : '';
                                                    meSelect.innerHTML += `<option value="${sub.id}" ${selected}>${sub.subject_name}</option>`;
                                                });
                                                // Do not force user selection — auto-assign and keep non-required
                                                meSelect.required = false;
                                                // Keep container hidden by default (we auto-fill main value)
                                                meContainer.style.display = 'none';
                                                autoAssignMainElective(); // Initial calc (fills meSelect.value)
                                            } else {
                                                meSelect.required = false;
                                                meSelect.value = '';
                                                meContainer.style.display = 'none';
                                            }
                                        }).catch(() => {
                                            // On error, hide main elective
                                            meSelect.required = false;
                                            meSelect.value = '';
                                            document.getElementById('main_elective_container').style.display = 'none';
                                        });
                                } else {
                                    meSelect.required = false;
                                    meSelect.value = '';
                                    document.getElementById('main_elective_container').style.display = 'none';
                                }
                            }
                        }

                        // ─── Last Roll Hint ─────────────────────────────────────────────
                        function fetchLastRoll() {
                            const classInput = document.getElementById('class_id');
                            const classId = classInput?.value;
                            const hintBox = document.getElementById('last_roll_hint');

                            if (!classId || !hintBox) return;

                            fetch(`get_last_roll.php?class_id=${classId}`)
                                .then(res => res.json())
                                .then(data => {
                                    hintBox.style.display = '';

                                    if (data.last_roll) {
                                        hintBox.innerHTML = `
                                            <span style="background:rgba(15,23,42,0.06);border:1px solid rgba(15,23,42,0.12);border-radius:6px;padding:4px 10px;color:#475569;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
                                                <i class="fas fa-info-circle" style="color:#94a3b8;"></i>
                                                Last roll: <strong>${data.last_roll}</strong>
                                                &nbsp;·&nbsp; Suggested next: <strong style="color:#0f172a;">${data.next_roll}</strong>
                                            </span>`;
                                    } else {
                                        hintBox.innerHTML = `
                                            <span style="background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2);border-radius:6px;padding:4px 10px;color:#16a34a;font-weight:600;display:inline-flex;align-items:center;gap:6px;">
                                                <i class="fas fa-star"></i> First student in this class
                                            </span>`;
                                    }
                                })
                                .catch(() => {
                                    if (hintBox) hintBox.style.display = 'none';
                                });
                        }

                        // Run on load
                        handleClassChange();
                        if (preSelectedClassId) {
                            updateGroupFields('all');
                            // hint already rendered by PHP for pre-selected class
                        } else {
                            // Dropdown mode: show hint for the first selected option
                            fetchLastRoll();
                        }
                    </script>
                </div>
            </div>
        </div>
    </div>

</body>

</html>
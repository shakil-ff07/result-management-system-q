<?php
include('auth.php');
require_admin_or_assistant();
include('../includes/db_config.php');

$id = $_GET['id'] ?? null;
if (!$id) {
    header("Location: manage-students.php");
    exit();
}

$message = "";
$error = "";

// Fetch classes (include academic year to avoid wrong session selection)
$stmt = $conn->query("SELECT * FROM classes ORDER BY academic_year DESC, LENGTH(class_name), class_name, section");
$classes = $stmt->fetchAll();

// Fetch student details
$stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    header("Location: manage-students.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $roll = $_POST['roll'];
    $name = $_POST['name'];
    $father = $_POST['father'];
    $mother = $_POST['mother'];
    $dob = $_POST['dob'];
    $class_id = $_POST['class_id'];
    $group = $_POST['group'];
    $main_elective_id = $_POST['main_elective_id'] ?: null;
    $optional_subject_id = $_POST['optional_subject_id'] ?: null;
    $phone = trim($_POST['phone'] ?? '') ?: null;

    // Keep electives when only profile fields changed (prevents marksheet subject list from clearing)
    $enrollment_unchanged = (
        (int) $class_id === (int) $student['class_id'] &&
        $group === $student['student_group']
    );
    if ($enrollment_unchanged) {
        if ($main_elective_id === null && !empty($student['main_elective_id'])) {
            $main_elective_id = $student['main_elective_id'];
        }
        if ($optional_subject_id === null && !empty($student['optional_subject_id'])) {
            $optional_subject_id = $student['optional_subject_id'];
        }
    }

    try {
        $stmt = $conn->prepare("UPDATE students SET roll_number = ?, name = ?, father_name = ?, mother_name = ?, dob = ?, class_id = ?, student_group = ?, main_elective_id = ?, optional_subject_id = ?, phone = ? WHERE id = ?");
        $stmt->execute([$roll, $name, $father, $mother, $dob, $class_id, $group, $main_elective_id, $optional_subject_id, $phone, $id]);
        
        $return_url = $_POST['return_url'] ?? '';
        if ($return_url !== '') {
            header("Location: " . $return_url);
            exit();
        }
        
        $message = "Student updated successfully!";
        // Refresh student data
        $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->execute([$id]);
        $student = $stmt->fetch();
    } catch (PDOException $e) {
        if ($e->getCode() == 23000 && strpos($e->getMessage(), '1062') !== false) {
            $error = "This Roll already exists in this class. Please choose a different roll number.";
        } else {
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Edit Student - SRMS Admin</title>
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
                        <i class="fas fa-user-edit"></i>
                    </div>
                    <div>
                        <h1 class="fw-bold mb-0">Edit Student</h1>
                        <p class="mb-0 text-primary fw-bold">Student: <?php echo htmlspecialchars($student['name']); ?>
                        </p>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="<?php echo htmlspecialchars($_GET['return_url'] ?? $_POST['return_url'] ?? 'manage-students.php'); ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3 shadow-sm">
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
                    <form action="" method="POST">
                        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($_GET['return_url'] ?? $_POST['return_url'] ?? ''); ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" name="name" class="form-control"
                                    value="<?php echo $student['name']; ?>" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Roll Number</label>
                                <input type="number" name="roll" class="form-control"
                                    value="<?php echo $student['roll_number']; ?>" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" name="dob" class="form-control"
                                    value="<?php echo $student['dob']; ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Father's Name</label>
                                <input type="text" name="father" class="form-control"
                                    value="<?php echo $student['father_name']; ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mother's Name</label>
                                <input type="text" name="mother" class="form-control"
                                    value="<?php echo $student['mother_name']; ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Class & Section</label>
                                <select name="class_id" id="class_id" class="form-select" required>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" 
                                                data-class-name="<?php echo htmlspecialchars($c['class_name']); ?>"
                                                <?php echo ($c['id'] == $student['class_id']) ? 'selected' : ''; ?>>
                                            <?php echo $c['class_name'] . " (" . $c['section'] . ") — " . $c['academic_year']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3" id="group_container">
                                <label class="form-label">Group (9-10 only)</label>
                                <select name="group" id="student_group" class="form-select">
                                    <option value="None" <?php echo ($student['student_group'] == 'None') ? 'selected' : ''; ?>>None</option>
                                    <option value="Science" <?php echo ($student['student_group'] == 'Science') ? 'selected' : ''; ?>>Science</option>
                                    <option value="Commerce" <?php echo ($student['student_group'] == 'Commerce') ? 'selected' : ''; ?>>Commerce</option>
                                    <option value="Arts" <?php echo ($student['student_group'] == 'Arts') ? 'selected' : ''; ?>>Arts</option>
                                </select>
                            </div>
                            <input type="hidden" name="main_elective_id" id="main_elective_id"
                                value="<?php echo htmlspecialchars($student['main_elective_id'] ?? ''); ?>">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Optional Subject</label>
                                <select name="optional_subject_id" id="optional_subject_id" class="form-select">
                                    <option value="">None</option>
                                    <?php
                                    if ($student['class_id']) {
                                        // Dynamically resolve selective subject IDs by name
                                        // (Biology & Higher Math are stored as is_optional=0 but can be chosen as optional)
                                        $sel_names = [];
                                        if ($student['student_group'] === 'Science') {
                                            $sel_names = ['Biology', 'Higher Mathematics'];
                                        } elseif ($student['student_group'] === 'Arts') {
                                            $sel_names = ['Geography & Environment', 'Economics'];
                                        }
                                        $sel_ids = [];
                                        if (!empty($sel_names)) {
                                            $pl = implode(',', array_fill(0, count($sel_names), '?'));
                                            $s = $conn->prepare("SELECT id FROM subjects WHERE subject_name IN ($pl)");
                                            $s->execute($sel_names);
                                            $sel_ids = $s->fetchAll(PDO::FETCH_COLUMN);
                                        }
                                        $id_clause = !empty($sel_ids) ? 'OR s.id IN (' . implode(',', $sel_ids) . ')' : '';
                                        $stmt_opt = $conn->prepare("SELECT s.* FROM subjects s
                                            JOIN class_subjects cs ON s.id = cs.subject_id
                                            WHERE cs.class_id = ?
                                              AND (cs.student_group = ? OR cs.student_group = 'None')
                                              AND (cs.is_optional = 1 $id_clause)
                                            ORDER BY s.subject_name ASC");
                                        $stmt_opt->execute([$student['class_id'], $student['student_group']]);
                                        $opt_subjects = $stmt_opt->fetchAll();
                                        foreach ($opt_subjects as $os) {
                                            $selected = ($os['id'] == $student['optional_subject_id']) ? 'selected' : '';
                                            echo "<option value='{$os['id']}' $selected>{$os['subject_name']}</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="fas fa-phone me-1 text-muted"></i>Phone Number <span class="text-muted" style="font-size:0.8rem;">(Optional)</span></label>
                                <input type="tel" name="phone" class="form-control"
                                    value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>" placeholder="e.g. 017XXXXXXXX">
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary px-4">Update Student</button>
                        </div>
                    </form>

                    <script>
                        const savedOptionalId = "<?php echo (int) ($student['optional_subject_id'] ?? 0); ?>";
                        const savedMainElectiveId = "<?php echo (int) ($student['main_elective_id'] ?? 0); ?>";

                        document.getElementById('class_id').addEventListener('change', function() {
                            handleClassChange(true);
                            updateGroupFields('class');
                        });
                        document.getElementById('student_group').addEventListener('change', () => updateGroupFields('group'));
                        document.getElementById('optional_subject_id').addEventListener('change', autoAssignMainElective);

                        function autoAssignMainElective() {
                            const group = document.getElementById('student_group').value;
                            const optionalVal = document.getElementById('optional_subject_id').value;
                            const meInput = document.getElementById('main_elective_id');

                            if (group !== 'Science') return;

                            if (optionalVal == '124') {
                                meInput.value = '125';
                            } else if (optionalVal == '125') {
                                meInput.value = '124';
                            } else if (optionalVal != '') {
                                meInput.value = '124';
                            } else if (!meInput.value && savedMainElectiveId) {
                                meInput.value = savedMainElectiveId;
                            }
                        }

                        function handleClassChange(fromUser = false) {
                            const classSelect = document.getElementById('class_id');
                            const selectedOption = classSelect.options[classSelect.selectedIndex];
                            const className = selectedOption.getAttribute('data-class-name') || '';
                            const groupContainer = document.getElementById('group_container');
                            const groupSelect = document.getElementById('student_group');

                            const lowerName = className.trim().toLowerCase();
                            const isLowerClass = (lowerName === 'class 6' || lowerName === 'class 7' || lowerName === 'class 8');
                            if (isLowerClass) {
                                groupContainer.style.display = 'none';
                                if (fromUser) {
                                    groupSelect.value = 'None';
                                    document.getElementById('main_elective_id').value = '';
                                }
                            } else {
                                groupContainer.style.display = 'block';
                            }
                        }

                        function updateGroupFields(source = 'all') {
                            const classId = document.getElementById('class_id').value;
                            const group = document.getElementById('student_group').value;
                            if (!classId) return;

                            const optionalSelect = document.getElementById('optional_subject_id');
                            const currentVal = optionalSelect.value || savedOptionalId;
                            const meInput = document.getElementById('main_elective_id');

                            fetch(`get_optional_subjects.php?class_id=${classId}&group=${group}&type=optional`)
                                .then(res => res.json())
                                .then(data => {
                                    optionalSelect.innerHTML = '<option value="">None</option>';
                                    data.forEach(sub => {
                                        if (sub.id != meInput.value) {
                                            const selected = (sub.id == currentVal) ? 'selected' : '';
                                            optionalSelect.innerHTML += `<option value="${sub.id}" ${selected}>${sub.subject_name}</option>`;
                                        }
                                    });
                                });

                            const selectedClassName = (document.getElementById('class_id').selectedOptions[0]?.getAttribute('data-class-name') || '').trim().toLowerCase();
                            if ((group === 'Science' || group === 'Arts') && selectedClassName !== 'class 6' && selectedClassName !== 'class 7' && selectedClassName !== 'class 8') {
                                fetch(`get_optional_subjects.php?class_id=${classId}&group=${group}&type=main_elective`)
                                    .then(res => res.json())
                                    .then(data => {
                                        if (data.length > 0) {
                                            const currentMeVal = meInput.value || savedMainElectiveId;
                                            const match = data.find(sub => sub.id == currentMeVal);
                                            meInput.value = match ? match.id : data[0].id;
                                            autoAssignMainElective();
                                        }
                                    });
                            } else if (source === 'class' || source === 'group') {
                                meInput.value = '';
                            }
                        }

                        // Only adjust group visibility on load — keep server-rendered optional subjects
                        handleClassChange(false);
                    </script>
                </div>
            </div>
        </div>
    </div>

</body>

</html>
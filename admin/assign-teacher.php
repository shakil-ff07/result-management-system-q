<?php
include('auth.php');
require_admin();
include('../includes/db_config.php');

$teacher_id = $_GET['id'] ?? null;
if (!$teacher_id) {
    header("Location: manage-users.php");
    exit();
}

// Fetch teacher info
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = ? AND role = 'teacher'");
$stmt->execute([$teacher_id]);
$teacher = $stmt->fetch();

if (!$teacher) {
    header("Location: manage-users.php?error=not_found");
    exit();
}

$msg = "";
$error = "";

// Handle Delete Assignment
if (isset($_GET['delete'])) {
    $assign_id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM teacher_assignments WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$assign_id, $teacher_id]);
    header("Location: assign-teacher.php?id=$teacher_id&msg=deleted");
    exit();
}

// Handle Add Assignment
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_assignment'])) {
    $sel_year = $_POST['year'];
    $sel_class = $_POST['class_name'];
    $sel_section = $_POST['section'];
    $subject_id = $_POST['subject_id'];

    try {
        // Find class_id
        $stmt_find = $conn->prepare("SELECT id FROM classes WHERE academic_year = ? AND class_name = ? AND section = ?");
        $stmt_find->execute([$sel_year, $sel_class, $sel_section]);
        $class_data = $stmt_find->fetch();

        if (!$class_data) {
            throw new Exception("Selected class/section not found.");
        }

        $class_id = $class_data['id'];

        $stmt = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, class_id, subject_id) VALUES (?, ?, ?)");
        $stmt->execute([$teacher_id, $class_id, $subject_id]);
        $msg = "Assignment added successfully!";
    } catch (Exception $e) {
        $error = "This assignment already exists or an error occurred!";
    }
}

// Fetch current assignments
$stmt = $conn->prepare("
    SELECT ta.id, c.class_name, c.section, c.academic_year, s.subject_name 
    FROM teacher_assignments ta
    JOIN classes c ON ta.class_id = c.id
    JOIN subjects s ON ta.subject_id = s.id
    WHERE ta.teacher_id = ?
    ORDER BY c.academic_year ASC, c.class_name, c.section
");
$stmt->execute([$teacher_id]);
$assignments = $stmt->fetchAll();

// Fetch all classes for JS filtering
$all_classes = $conn->query("SELECT * FROM classes ORDER BY academic_year ASC, class_name, section")->fetchAll(PDO::FETCH_ASSOC);
$years = array_unique(array_column($all_classes, 'academic_year'));
$current_year = date('Y');

// Fetch all subjects mapped to classes for JS filtering
$stmt_sub = $conn->query("
    SELECT cs.class_id, s.id as subject_id, s.subject_name 
    FROM class_subjects cs
    JOIN subjects s ON cs.subject_id = s.id
");
$all_subjects = $stmt_sub->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Assign Subjects - <?php echo $teacher['username']; ?></title>
    <?php include('header.php'); ?>
    <style>
        .assignment-card {
            background: #fff;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            border: 1px solid var(--prestige-border);
            transition: transform 0.2s;
        }
        
        .assignment-card:active {
            transform: scale(0.98);
        }

        .assignment-card-year {
            font-weight: 700;
            color: var(--prestige-gold);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .assignment-card-class {
            font-weight: 700;
            color: var(--prestige-navy);
            font-size: 1.1rem;
            margin: 2px 0;
        }

        .assignment-card-subject {
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .header-actions {
                width: 100%;
                margin-top: 15px;
            }
            .header-actions .btn {
                width: 100%;
                justify-content: center;
                padding: 10px;
            }
            #content {
                padding: 15px !important;
            }
        }

        [data-theme="dark"] .assignment-card {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
        }
        [data-theme="dark"] .assignment-card-class { color: #e2e8f0 !important; }
        [data-theme="dark"] .assignment-card-subject { color: #94a3b8 !important; }
    </style>
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
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div>
                        <h1 class="fw-bold mb-0">Teacher Assignments</h1>
                        <p class="mb-0 text-primary fw-bold">User: <?php echo htmlspecialchars($teacher['username']); ?>
                        </p>
                    </div>
                </div>
                <div class="header-actions">
                    <a href="manage-users.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 shadow-sm">
                        <i class="fas fa-arrow-left me-1"></i> Back to Users
                    </a>
                </div>
            </div>

            <?php if ($msg || isset($_GET['msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show shadow">
                    <?php echo $msg ?: ($_GET['msg'] == 'deleted' ? 'Assignment removed successfully!' : ''); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger shadow"><?php echo $error; ?></div>
            <?php endif; ?>

            <div class="row">
                <!-- Current Assignments -->
                <div class="col-lg-8">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 bg-white">
                            <h6 class="m-0 font-weight-bold text-primary">Current Responsibilities</h6>
                        </div>
                        <div class="card-body">
                            <!-- Desktop Table -->
                            <div class="table-responsive d-none d-md-block">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Academic Year</th>
                                            <th>Class & Section</th>
                                            <th>Subject</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($assignments)): ?>
                                            <tr>
                                                <td colspan="4" class="text-center py-4 text-muted">
                                                    No subjects assigned to this teacher yet.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($assignments as $a): ?>
                                                <tr>
                                                    <td class="font-weight-bold text-primary"><?php echo $a['academic_year']; ?></td>
                                                    <td>
                                                        <strong><?php echo $a['class_name']; ?></strong>
                                                        <span class="badge bg-light text-dark border ms-2">Section <?php echo $a['section']; ?></span>
                                                    </td>
                                                    <td><?php echo $a['subject_name']; ?></td>
                                                    <td class="text-end">
                                                        <a href="?id=<?php echo $teacher_id; ?>&delete=<?php echo $a['id']; ?>"
                                                            class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('Remove this assignment?')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Mobile Cards -->
                            <div class="d-md-none">
                                <?php if (empty($assignments)): ?>
                                    <div class="text-center py-4 text-muted border rounded-3 bg-light">
                                        <i class="fas fa-info-circle mb-2 d-block fa-lg"></i>
                                        No subjects assigned yet.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($assignments as $a): ?>
                                        <div class="assignment-card shadow-sm">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <div class="assignment-card-year"><?php echo $a['academic_year']; ?></div>
                                                    <div class="assignment-card-class">
                                                        <?php echo $a['class_name']; ?>
                                                        <span class="badge bg-light text-dark border ms-1" style="font-size: 0.6rem;">SEC <?php echo $a['section']; ?></span>
                                                    </div>
                                                    <div class="assignment-card-subject">
                                                        <i class="fas fa-book-open me-1 small opacity-50"></i> <?php echo $a['subject_name']; ?>
                                                    </div>
                                                </div>
                                                <a href="?id=<?php echo $teacher_id; ?>&delete=<?php echo $a['id']; ?>"
                                                    class="btn btn-outline-danger border-0"
                                                    onclick="return confirm('Remove this assignment?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Add New Assignment -->
                <div class="col-lg-4">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 bg-white">
                            <h6 class="m-0 font-weight-bold text-primary">Add New Assignment</h6>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="">
                                <div class="mb-3">
                                    <label class="form-label font-weight-bold small text-uppercase">Academic
                                        Year</label>
                                    <select name="year" id="year" class="form-select" required
                                        onchange="updateClasses()">
                                        <?php foreach ($years as $y): ?>
                                            <option value="<?php echo $y; ?>" <?php echo $y == $current_year ? 'selected' : ''; ?>>
                                                <?php echo $y; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label font-weight-bold small text-uppercase">Select Class</label>
                                    <select name="class_name" id="class_name" class="form-select" required
                                        onchange="updateSections()">
                                        <option value="">-- Choose Class --</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label font-weight-bold small text-uppercase">Section</label>
                                    <select name="section" id="section" class="form-select" required
                                        onchange="updateSubjects()">
                                        <option value="">-- Select Section --</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label font-weight-bold small text-uppercase">Subject</label>
                                    <select name="subject_id" id="subject_id" class="form-select" required disabled>
                                        <option value="">-- Select Section First --</option>
                                    </select>
                                </div>
                                <button type="submit" name="add_assignment"
                                    class="btn btn-primary w-100 py-2 shadow-sm">
                                    <i class="fas fa-plus-circle me-1"></i> Add Responsibility
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const allClasses = <?php echo json_encode($all_classes); ?>;
        const allSubjects = <?php echo json_encode($all_subjects); ?>;

        function updateClasses() {
            const year = document.getElementById('year').value;
            const classSelect = document.getElementById('class_name');
            const sectionSelect = document.getElementById('section');
            const subjectSelect = document.getElementById('subject_id');

            const filteredClasses = [...new Set(allClasses
                .filter(c => c.academic_year == year)
                .map(c => c.class_name))];

            classSelect.innerHTML = '<option value="">-- Choose Class --</option>';
            filteredClasses.forEach(cls => {
                const opt = document.createElement('option');
                opt.value = cls;
                opt.textContent = cls;
                classSelect.appendChild(opt);
            });

            sectionSelect.innerHTML = '<option value="">-- Select Section --</option>';
            subjectSelect.innerHTML = '<option value="">-- Select Section First --</option>';
            subjectSelect.disabled = true;
        }

        function updateSections() {
            const year = document.getElementById('year').value;
            const className = document.getElementById('class_name').value;
            const sectionSelect = document.getElementById('section');
            const subjectSelect = document.getElementById('subject_id');

            if (!className) {
                sectionSelect.innerHTML = '<option value="">-- Select Section --</option>';
                return;
            }

            const sections = allClasses
                .filter(c => c.academic_year == year && c.class_name == className)
                .map(c => ({ id: c.id, section: c.section }));

            sectionSelect.innerHTML = '<option value="">-- Choose Section --</option>';
            sections.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.section;
                opt.dataset.classId = s.id;
                opt.textContent = s.section;
                sectionSelect.appendChild(opt);
            });

            subjectSelect.innerHTML = '<option value="">-- Select Section First --</option>';
            subjectSelect.disabled = true;
        }

        function updateSubjects() {
            const sectionSelect = document.getElementById('section');
            const subjectSelect = document.getElementById('subject_id');
            const selectedOption = sectionSelect.options[sectionSelect.selectedIndex];

            if (!selectedOption || !selectedOption.dataset.classId) {
                subjectSelect.innerHTML = '<option value="">-- Select Section First --</option>';
                subjectSelect.disabled = true;
                return;
            }

            const classId = selectedOption.dataset.classId;
            const subjects = allSubjects.filter(s => s.class_id == classId);

            subjectSelect.innerHTML = '<option value="">-- Choose Subject --</option>';
            if (subjects.length > 0) {
                subjectSelect.disabled = false;
                subjects.forEach(sub => {
                    const opt = document.createElement('option');
                    opt.value = sub.subject_id;
                    opt.textContent = sub.subject_name;
                    subjectSelect.appendChild(opt);
                });
            } else {
                subjectSelect.disabled = true;
                subjectSelect.innerHTML = '<option value="">-- No Subjects in Class --</option>';
            }
        }

        // Initialize
        window.onload = updateClasses;
    </script>
</body>

</html>
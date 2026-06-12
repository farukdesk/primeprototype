/// Student model returned by the API.
class StudentModel {
  // User account fields
  final int    userId;
  final String username;
  final String email;

  // Student profile fields
  final int    studentDbId;
  final String studentId;
  final String fullName;
  final String? photoUrl;
  final String? phone;
  final String? studentEmail;
  final String  status;
  final String  deptName;
  final String  deptCode;
  final String? programName;
  final String? programType;
  final String? batchName;

  // Summary stats
  final int     noticesUniversity;
  final int     notesDepartment;
  final double? outstandingBalance;

  const StudentModel({
    required this.userId,
    required this.username,
    required this.email,
    required this.studentDbId,
    required this.studentId,
    required this.fullName,
    this.photoUrl,
    this.phone,
    this.studentEmail,
    required this.status,
    required this.deptName,
    required this.deptCode,
    this.programName,
    this.programType,
    this.batchName,
    this.noticesUniversity = 0,
    this.notesDepartment   = 0,
    this.outstandingBalance,
  });

  factory StudentModel.fromLoginJson(Map<String, dynamic> json) {
    final user    = json['user']    as Map<String, dynamic>;
    final student = json['student'] as Map<String, dynamic>;
    return StudentModel(
      userId:       (user['id']       as num).toInt(),
      username:      user['username'] as String,
      email:         user['email']    as String,
      studentDbId:  (student['id']   as num).toInt(),
      studentId:     student['student_id']   as String? ?? '',
      fullName:      student['full_name']     as String,
      photoUrl:      student['photo_url']     as String?,
      phone:         student['phone']         as String?,
      studentEmail:  student['email']         as String?,
      status:        student['status']        as String? ?? '',
      deptName:      student['dept_name']     as String? ?? '',
      deptCode:      student['dept_code']     as String? ?? '',
      programName:   student['program_name']  as String?,
      programType:   student['program_type']  as String?,
      batchName:     student['batch_name']    as String?,
    );
  }

  factory StudentModel.fromMeJson(Map<String, dynamic> json) {
    final user    = json['user']    as Map<String, dynamic>;
    final student = json['student'] as Map<String, dynamic>;
    final stats   = json['stats']   as Map<String, dynamic>? ?? {};
    return StudentModel(
      userId:       (user['id']      as num).toInt(),
      username:      user['username'] as String,
      email:         user['email']    as String,
      studentDbId:  (student['id']  as num).toInt(),
      studentId:     student['student_id']   as String? ?? '',
      fullName:      student['full_name']     as String,
      photoUrl:      student['photo_url']     as String?,
      phone:         student['phone']         as String?,
      studentEmail:  student['email']         as String?,
      status:        student['status']        as String? ?? '',
      deptName:      student['dept_name']     as String? ?? '',
      deptCode:      student['dept_code']     as String? ?? '',
      programName:   student['program_name']  as String?,
      programType:   student['program_type']  as String?,
      batchName:     student['batch_name']    as String?,
      noticesUniversity: (stats['notices_university'] as num?)?.toInt() ?? 0,
      notesDepartment:   (stats['notices_department'] as num?)?.toInt() ?? 0,
      outstandingBalance: stats['outstanding_balance'] != null
          ? (stats['outstanding_balance'] as num).toDouble()
          : null,
    );
  }

  String get initials {
    final parts = fullName.trim().split(RegExp(r'\s+'));
    if (parts.isEmpty) return '?';
    if (parts.length == 1) return parts[0][0].toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }
}

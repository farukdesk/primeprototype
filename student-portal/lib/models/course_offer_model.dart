/// A course offer targeted at the student's batch, with its subjects.
class CourseOffer {
  final int id;
  final String? semester;
  final String? academicIntake;
  final bool registrationOpen;
  final String deptName;
  final String programName;
  final String batchName;
  final List<OfferSubject> subjects;
  final int registeredCount;
  final int totalSubjects;

  const CourseOffer({
    required this.id,
    required this.semester,
    required this.academicIntake,
    required this.registrationOpen,
    required this.deptName,
    required this.programName,
    required this.batchName,
    required this.subjects,
    required this.registeredCount,
    required this.totalSubjects,
  });

  factory CourseOffer.fromJson(Map<String, dynamic> j) {
    return CourseOffer(
      id:               (j['id'] as num).toInt(),
      semester:          j['semester'] as String?,
      academicIntake:    j['academic_intake'] as String?,
      registrationOpen:  j['registration_open'] == true,
      deptName:          j['dept_name'] as String? ?? '',
      programName:       j['program_name'] as String? ?? '',
      batchName:         j['batch_name'] as String? ?? '',
      subjects: ((j['subjects'] as List?) ?? [])
          .map((s) => OfferSubject.fromJson(s as Map<String, dynamic>))
          .toList(),
      registeredCount:  (j['registered_count'] as num?)?.toInt() ?? 0,
      totalSubjects:    (j['total_subjects'] as num?)?.toInt() ?? 0,
    );
  }
}

/// A single subject inside a course offer.
class OfferSubject {
  final int offerSubjectId;
  final String? courseCode;
  final String courseName;
  final String? credit;
  bool registered;
  final List<OfferTeacher> teachers;

  OfferSubject({
    required this.offerSubjectId,
    required this.courseCode,
    required this.courseName,
    required this.credit,
    required this.registered,
    required this.teachers,
  });

  factory OfferSubject.fromJson(Map<String, dynamic> j) {
    return OfferSubject(
      offerSubjectId: (j['offer_subject_id'] as num).toInt(),
      courseCode:      j['course_code'] as String?,
      courseName:      j['course_name'] as String? ?? '',
      credit:          j['credit']?.toString(),
      registered:      j['registered'] == true,
      teachers: ((j['teachers'] as List?) ?? [])
          .map((t) => OfferTeacher.fromJson(t as Map<String, dynamic>))
          .toList(),
    );
  }
}

/// A teacher assigned to an offer subject.
class OfferTeacher {
  final String name;
  final String? designation;

  const OfferTeacher({required this.name, this.designation});

  factory OfferTeacher.fromJson(Map<String, dynamic> j) => OfferTeacher(
        name:        j['name'] as String? ?? '',
        designation: j['designation'] as String?,
      );
}

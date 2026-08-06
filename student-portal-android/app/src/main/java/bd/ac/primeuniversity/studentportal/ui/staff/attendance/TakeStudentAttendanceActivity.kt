package bd.ac.primeuniversity.studentportal.ui.staff.attendance

import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.TeachSubject
import bd.ac.primeuniversity.studentportal.databinding.ActivityTakeStudentAttendanceBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import com.google.android.material.datepicker.MaterialDatePicker
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/**
 * Faculty: take or edit student attendance for one offered subject on one
 * date. Every registered student defaults to Present; tap P/A/L/E to change,
 * use the All Present / All Absent shortcuts, or pick another date to edit
 * history. Saving a date that already has attendance overwrites it.
 */
class TakeStudentAttendanceActivity : AppCompatActivity() {

    private lateinit var binding: ActivityTakeStudentAttendanceBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    private val apiFormat = SimpleDateFormat("yyyy-MM-dd", Locale.US)
    private val utcFormat = SimpleDateFormat("yyyy-MM-dd", Locale.US).apply {
        timeZone = TimeZone.getTimeZone("UTC")
    }
    private val displayFormat = SimpleDateFormat("dd MMM yyyy", Locale.US)

    private var subjectId = 0
    private var date: String = ""

    private val adapter = StudentStatusAdapter()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityTakeStudentAttendanceBinding.inflate(layoutInflater)
        setContentView(binding.root)

        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { _, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            binding.root.updatePadding(top = bars.top, bottom = bars.bottom)
            insets
        }

        subjectId = intent.getIntExtra(EXTRA_SUBJECT_ID, 0)
        binding.title.text =
            intent.getStringExtra(EXTRA_TITLE) ?: getString(R.string.take_attendance)
        binding.subtitle.text = intent.getStringExtra(EXTRA_SUBTITLE) ?: ""
        date = savedInstanceState?.getString(STATE_DATE) ?: apiFormat.format(Date())

        binding.btnBack.setOnClickListener { finish() }

        binding.studentList.layoutManager = LinearLayoutManager(this)
        binding.studentList.adapter = adapter

        binding.btnDate.setOnClickListener { pickDate() }
        binding.btnAllPresent.setOnClickListener {
            adapter.markAll(StudentStatusAdapter.STATUS_PRESENT)
        }
        binding.btnAllAbsent.setOnClickListener {
            adapter.markAll(StudentStatusAdapter.STATUS_ABSENT)
        }
        binding.btnSave.setOnClickListener { save() }

        updateDateButton()
        load()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putString(STATE_DATE, date)
    }

    private fun updateDateButton() {
        binding.btnDate.text = try {
            displayFormat.format(apiFormat.parse(date)!!)
        } catch (_: Exception) {
            date
        }
    }

    private fun pickDate() {
        val selection = try {
            utcFormat.parse(date)?.time
        } catch (_: Exception) {
            null
        } ?: MaterialDatePicker.todayInUtcMilliseconds()
        val picker = MaterialDatePicker.Builder.datePicker()
            .setTitleText(getString(R.string.class_date))
            .setSelection(selection)
            .build()
        picker.addOnPositiveButtonClickListener { millis ->
            date = utcFormat.format(Date(millis))
            updateDateButton()
            load()
        }
        picker.show(supportFragmentManager, "class_date")
    }

    private fun load() {
        binding.progress.visibility = View.VISIBLE
        binding.emptyView.visibility = View.GONE
        binding.existingBadge.visibility = View.GONE
        lifecycleScope.launch {
            when (val res = app.repository.getSubjectStudents(subjectId, date)) {
                is AppResult.Success -> {
                    adapter.submit(res.data.students, res.data.statuses)
                    binding.existingBadge.visibility =
                        if (res.data.hasSession) View.VISIBLE else View.GONE
                    val empty = res.data.students.isEmpty()
                    binding.emptyView.visibility = if (empty) View.VISIBLE else View.GONE
                    binding.btnSave.isEnabled = !empty
                }
                is AppResult.Error -> {
                    binding.btnSave.isEnabled = false
                    Toast.makeText(this@TakeStudentAttendanceActivity, res.message, Toast.LENGTH_LONG).show()
                }
            }
            binding.progress.visibility = View.GONE
        }
    }

    private fun save() {
        val statuses = adapter.snapshot()
        if (statuses.isEmpty()) return
        binding.btnSave.isEnabled = false
        lifecycleScope.launch {
            when (val res = app.repository.saveStudentAttendance(subjectId, date, statuses)) {
                is AppResult.Success -> {
                    binding.existingBadge.visibility = View.VISIBLE
                    Toast.makeText(
                        this@TakeStudentAttendanceActivity,
                        getString(R.string.attendance_saved),
                        Toast.LENGTH_SHORT,
                    ).show()
                }
                is AppResult.Error ->
                    Toast.makeText(this@TakeStudentAttendanceActivity, res.message, Toast.LENGTH_LONG).show()
            }
            binding.btnSave.isEnabled = true
        }
    }

    companion object {
        private const val EXTRA_SUBJECT_ID = "subject_id"
        private const val EXTRA_TITLE = "title"
        private const val EXTRA_SUBTITLE = "subtitle"
        private const val STATE_DATE = "date"

        fun intent(context: Context, subject: TeachSubject): Intent =
            Intent(context, TakeStudentAttendanceActivity::class.java)
                .putExtra(EXTRA_SUBJECT_ID, subject.id)
                .putExtra(
                    EXTRA_TITLE,
                    listOfNotNull(
                        subject.courseCode?.takeIf { it.isNotBlank() },
                        subject.courseName?.takeIf { it.isNotBlank() },
                    ).joinToString(" \u2014 "),
                )
                .putExtra(
                    EXTRA_SUBTITLE,
                    listOfNotNull(
                        subject.batchName?.takeIf { it.isNotBlank() },
                        subject.semester?.takeIf { it.isNotBlank() },
                        subject.academicIntake?.takeIf { it.isNotBlank() },
                        subject.section?.takeIf { it.isNotBlank() }?.let { "Sec $it" },
                        subject.shift?.takeIf { it.isNotBlank() },
                    ).joinToString(" \u00b7 "),
                )
    }
}

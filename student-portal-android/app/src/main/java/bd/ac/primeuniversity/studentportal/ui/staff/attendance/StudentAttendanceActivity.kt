package bd.ac.primeuniversity.studentportal.ui.staff.attendance

import android.os.Bundle
import android.view.View
import android.widget.ArrayAdapter
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.TeachSubject
import bd.ac.primeuniversity.studentportal.databinding.ActivityStudentAttendanceBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import com.google.android.material.textfield.MaterialAutoCompleteTextView
import kotlinx.coroutines.launch

/**
 * Faculty: pick one of your assigned course-offer subjects to take student
 * attendance. Filters cascade Department → Program → Batch → Semester →
 * Academic Intake → Section → Shift. Every option list only offers values
 * that exist among your own subjects; the Department defaults to your
 * faculty profile's department; Section/Shift only appear when the current
 * subjects actually define them; single options are auto-selected.
 */
class StudentAttendanceActivity : AppCompatActivity() {

    private lateinit var binding: ActivityStudentAttendanceBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    private var all: List<TeachSubject> = emptyList()
    private var loaded = false

    /** Selected value per filter level (null = All). */
    private val selections = arrayOfNulls<String>(7)

    private lateinit var subjectAdapter: TeachSubjectAdapter

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityStudentAttendanceBinding.inflate(layoutInflater)
        setContentView(binding.root)

        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { _, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            binding.root.updatePadding(top = bars.top, bottom = bars.bottom)
            insets
        }

        binding.btnBack.setOnClickListener { finish() }

        subjectAdapter = TeachSubjectAdapter { subject ->
            startActivity(TakeStudentAttendanceActivity.intent(this, subject))
        }
        binding.subjectList.layoutManager = LinearLayoutManager(this)
        binding.subjectList.adapter = subjectAdapter

        dropdowns().forEachIndexed { level, dd ->
            dd.setOnItemClickListener { _, _, _, _ ->
                val value = dd.text?.toString()?.takeIf { it != getString(R.string.filter_all) }
                selections[level] = value
                // Changing a filter clears every filter below it.
                for (i in level + 1 until selections.size) selections[i] = null
                rebuildFilters()
            }
        }

        binding.btnRetry.setOnClickListener { load() }
        load()
    }

    override fun onResume() {
        super.onResume()
        // Class counts change after taking attendance – refresh quietly.
        if (loaded) load(silent = true)
    }

    private fun dropdowns(): List<MaterialAutoCompleteTextView> = listOf(
        binding.ddDept, binding.ddProgram, binding.ddBatch, binding.ddSemester,
        binding.ddIntake, binding.ddSection, binding.ddShift,
    )

    private fun load(silent: Boolean = false) {
        if (!silent) {
            binding.progress.visibility = View.VISIBLE
            binding.errorWrap.visibility = View.GONE
        }
        lifecycleScope.launch {
            when (val res = app.repository.getTeachingSubjects()) {
                is AppResult.Success -> {
                    loaded = true
                    all = res.data.subjects
                    // Department defaults to the faculty profile's department.
                    if (selections[0] == null) {
                        val deptId = res.data.faculty?.deptId ?: 0
                        selections[0] = all.firstOrNull { it.deptId == deptId }?.deptName
                    }
                    rebuildFilters()
                }
                is AppResult.Error -> if (!silent) {
                    binding.errorText.text = res.message
                    binding.errorWrap.visibility = View.VISIBLE
                }
            }
            binding.progress.visibility = View.GONE
        }
    }

    private fun rebuildFilters() {
        var pool = all
        pool = bindLevel(0, binding.ddDept, pool) { it.deptName }
        pool = bindLevel(1, binding.ddProgram, pool) { it.programName }
        pool = bindLevel(2, binding.ddBatch, pool) { it.batchName }
        pool = bindLevel(3, binding.ddSemester, pool) { it.semester }
        pool = bindLevel(4, binding.ddIntake, pool) { it.academicIntake }
        // Not every batch has a Section or Shift – these filters only appear
        // when the current subjects actually carry values.
        pool = bindOptionalLevel(5, binding.sectionWrap, binding.ddSection, pool) { it.section }
        pool = bindOptionalLevel(6, binding.shiftWrap, binding.ddShift, pool) { it.shift }

        subjectAdapter.submit(pool)
        binding.noSubjectsView.visibility =
            if (loaded && all.isEmpty()) View.VISIBLE else View.GONE
        binding.emptyView.visibility =
            if (loaded && all.isNotEmpty() && pool.isEmpty()) View.VISIBLE else View.GONE
    }

    /** Populates one dropdown from [pool] and returns the pool filtered by it. */
    private fun bindLevel(
        level: Int,
        view: MaterialAutoCompleteTextView,
        pool: List<TeachSubject>,
        valueOf: (TeachSubject) -> String?,
    ): List<TeachSubject> {
        val options = pool.mapNotNull { valueOf(it)?.takeIf { v -> v.isNotBlank() } }
            .distinct().sorted()
        val allLabel = getString(R.string.filter_all)
        view.setAdapter(
            ArrayAdapter(this, android.R.layout.simple_list_item_1, listOf(allLabel) + options)
        )
        // Keep the previous choice while still valid; auto-select single options.
        val selected = selections[level]?.takeIf { it in options } ?: options.singleOrNull()
        selections[level] = selected
        view.setText(selected ?: allLabel, false)
        return if (selected == null) pool else pool.filter { valueOf(it) == selected }
    }

    /** Like [bindLevel] but hides the whole field when no subject has a value. */
    private fun bindOptionalLevel(
        level: Int,
        wrap: View,
        view: MaterialAutoCompleteTextView,
        pool: List<TeachSubject>,
        valueOf: (TeachSubject) -> String?,
    ): List<TeachSubject> {
        val available = pool.any { !valueOf(it).isNullOrBlank() }
        wrap.visibility = if (available) View.VISIBLE else View.GONE
        if (!available) {
            selections[level] = null
            return pool
        }
        return bindLevel(level, view, pool, valueOf)
    }
}

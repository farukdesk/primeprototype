package bd.ac.primeuniversity.studentportal.ui.results

import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.ResultsResponse
import bd.ac.primeuniversity.studentportal.databinding.ActivityResultsBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/**
 * Results screen. Shows the student's published semester results the same
 * way the web result page (spring-result.php) does: a student header, then
 * one card per result set with course-wise letter grades, grade points,
 * credits and the semester GPA. Data comes from admin/api/student/results.php.
 */
class ResultsActivity : AppCompatActivity() {

    private lateinit var binding: ActivityResultsBinding
    private val app: PrimeApp by lazy { application as PrimeApp }
    private val adapter = ResultSetAdapter()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityResultsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }
        binding.list.layoutManager = LinearLayoutManager(this)
        binding.list.adapter = adapter

        binding.swipeRefresh.setColorSchemeResources(
            R.color.primary, R.color.accent, R.color.cat_exam
        )
        binding.swipeRefresh.setOnRefreshListener { load() }

        load(initial = true)
    }

    private fun load(initial: Boolean = false) {
        if (initial) binding.progress.visibility = View.VISIBLE
        binding.emptyState.visibility = View.GONE

        lifecycleScope.launch {
            when (val result = app.repository.getResults()) {
                is AppResult.Success -> render(result.data)
                is AppResult.Error -> {
                    Toast.makeText(this@ResultsActivity, result.message, Toast.LENGTH_LONG).show()
                    if (adapter.itemCount == 0) binding.emptyState.visibility = View.VISIBLE
                }
            }
            binding.progress.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false
        }
    }

    private fun render(data: ResultsResponse) {
        val results = data.results
        adapter.submitList(results)

        val hasResults = results.isNotEmpty()
        binding.emptyState.visibility = if (hasResults) View.GONE else View.VISIBLE
        binding.studentHeader.visibility = if (hasResults) View.VISIBLE else View.GONE
        binding.footerNote.visibility = if (hasResults) View.VISIBLE else View.GONE
        if (!hasResults) return

        // Prefer what the result sheet says; fall back to the signed-in profile.
        val student = app.currentStudent.value
        val name = data.studentName?.takeIf { it.isNotBlank() }
            ?: student?.fullName?.takeIf { it.isNotBlank() }
        val id = data.studentId?.takeIf { it.isNotBlank() }
            ?: student?.studentId?.takeIf { it.isNotBlank() }

        binding.avatarInitial.text =
            name?.firstOrNull { it.isLetter() }?.uppercaseChar()?.toString() ?: "S"
        binding.headerStudentId.text = id ?: getString(R.string.dash)
        binding.headerStudentName.text = name ?: getString(R.string.student)
        binding.headerStudentName.visibility = if (name == null) View.GONE else View.VISIBLE
        binding.headerCount.text = resources.getQuantityString(
            R.plurals.results_sets_found, results.size, results.size
        )
    }
}

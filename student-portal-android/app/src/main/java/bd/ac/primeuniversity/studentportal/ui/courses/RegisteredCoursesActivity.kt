package bd.ac.primeuniversity.studentportal.ui.courses

import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.CourseOffer
import bd.ac.primeuniversity.studentportal.databinding.ActivityRegisteredCoursesBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/**
 * Registered Courses screen. Fetches the student's course offers from
 * admin/api/student/course-offers.php and lists only the subjects the student
 * has actually registered for, grouped by semester / academic intake.
 */
class RegisteredCoursesActivity : AppCompatActivity() {

    private lateinit var binding: ActivityRegisteredCoursesBinding
    private val app: PrimeApp by lazy { application as PrimeApp }
    private val adapter = RegisteredCoursesAdapter()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityRegisteredCoursesBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        binding.list.layoutManager = LinearLayoutManager(this)
        binding.list.adapter = adapter

        binding.swipeRefresh.setColorSchemeResources(
            R.color.primary, R.color.accent, R.color.info
        )
        binding.swipeRefresh.setOnRefreshListener { load() }

        load(initial = true)
    }

    private fun load(initial: Boolean = false) {
        if (initial) binding.progress.visibility = View.VISIBLE
        binding.emptyState.visibility = View.GONE

        lifecycleScope.launch {
            when (val result = app.repository.getCourseOffers()) {
                is AppResult.Success -> {
                    val rows = buildRows(result.data.offers)
                    adapter.submitList(rows)
                    result.data.message?.takeIf { rows.isEmpty() && it.isNotBlank() }?.let {
                        binding.emptyState.text = it
                    }
                    binding.emptyState.visibility =
                        if (rows.isEmpty()) View.VISIBLE else View.GONE
                }
                is AppResult.Error -> {
                    Toast.makeText(
                        this@RegisteredCoursesActivity, result.message, Toast.LENGTH_LONG
                    ).show()
                    if (adapter.itemCount == 0) binding.emptyState.visibility = View.VISIBLE
                }
            }
            binding.progress.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false
        }
    }

    /**
     * Keeps only the subjects the student registered for, grouped under the
     * offer (semester / academic intake) they belong to. Offers with no
     * registrations are hidden entirely.
     */
    private fun buildRows(offers: List<CourseOffer>): List<CourseRow> = buildList {
        offers.forEach { offer ->
            val registered = offer.subjects.filter { it.registered }
            if (registered.isNotEmpty()) {
                add(CourseRow.OfferHeader(offer))
                registered.forEach { add(CourseRow.Subject(it)) }
            }
        }
    }
}

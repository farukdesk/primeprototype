package bd.ac.primeuniversity.studentportal.ui.courses

import android.graphics.Typeface
import android.os.Bundle
import android.view.View
import android.view.ViewGroup
import android.widget.CheckBox
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.CourseOffer
import bd.ac.primeuniversity.studentportal.databinding.ActivityCourseRegistrationBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import kotlinx.coroutines.launch

/**
 * Course Registration screen.
 *
 * Lists the course offers currently open for self-registration for the
 * student's batch. The student must select ALL offered courses before the
 * Register button unlocks; the submitted registration awaits departmental
 * approval, and the status (pending/approved) is shown afterwards.
 */
class CourseRegistrationActivity : AppCompatActivity() {

    private lateinit var binding: ActivityCourseRegistrationBinding
    private val app: PrimeApp by lazy { application as PrimeApp }
    private var submitting = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityCourseRegistrationBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.toolbar.setNavigationOnClickListener { finish() }
        load()
    }

    private fun load() {
        binding.progress.visibility = View.VISIBLE
        binding.emptyState.visibility = View.GONE
        binding.offersContainer.removeAllViews()

        lifecycleScope.launch {
            when (val result = app.repository.getCourseOffers()) {
                is AppResult.Success -> {
                    val visible = result.data.offers.filter {
                        it.registrationOpen || it.registeredCount > 0
                    }
                    if (visible.isEmpty()) {
                        binding.emptyState.visibility = View.VISIBLE
                    } else {
                        visible.forEach { binding.offersContainer.addView(buildOfferCard(it)) }
                    }
                }
                is AppResult.Error -> {
                    Toast.makeText(this@CourseRegistrationActivity, result.message, Toast.LENGTH_LONG).show()
                    binding.emptyState.visibility = View.VISIBLE
                }
            }
            binding.progress.visibility = View.GONE
        }
    }

    // ── Offer card ─────────────────────────────────────────────────────────

    private fun buildOfferCard(offer: CourseOffer): View {
        val body = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(16), dp(16), dp(16), dp(16))
        }
        val card = MaterialCardView(this).apply {
            radius = dp(16).toFloat()
            cardElevation = dp(2).toFloat()
            setCardBackgroundColor(color(R.color.surface))
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT,
            ).apply { setMargins(0, 0, 0, dp(16)) }
            addView(body)
        }

        // Header: semester / intake, then dept · program · batch.
        body.addView(TextView(this).apply {
            text = listOfNotNull(
                offer.semester?.takeIf { it.isNotBlank() },
                offer.academicIntake?.takeIf { it.isNotBlank() },
            ).joinToString(" · ").ifEmpty { getString(R.string.feat_course_registration) }
            setTextColor(color(R.color.text_primary))
            textSize = 16f
            setTypeface(typeface, Typeface.BOLD)
        })
        body.addView(TextView(this).apply {
            text = listOfNotNull(
                offer.deptName?.takeIf { it.isNotBlank() },
                offer.programName?.takeIf { it.isNotBlank() },
                offer.batchName?.takeIf { it.isNotBlank() },
            ).joinToString(" · ")
            setTextColor(color(R.color.text_secondary))
            textSize = 12f
        })

        when {
            offer.registeredCount > 0 -> addStatusView(body, offer)
            offer.registrationOpen -> addRegistrationForm(body, offer)
            else -> body.addView(TextView(this).apply {
                text = getString(R.string.course_reg_closed)
                setTextColor(color(R.color.text_secondary))
                textSize = 13f
                setPadding(0, dp(12), 0, 0)
            })
        }
        return card
    }

    /** Submitted registration: overall + per-course status. */
    private fun addStatusView(body: LinearLayout, offer: CourseOffer) {
        val pending = offer.pendingCount > 0 ||
            offer.subjects.any { it.registered && it.approvalStatus == STATUS_PENDING }

        body.addView(TextView(this).apply {
            text = getString(
                if (pending) R.string.course_reg_status_pending
                else R.string.course_reg_status_approved,
            )
            setTextColor(color(if (pending) R.color.accent else R.color.success))
            textSize = 13f
            setTypeface(typeface, Typeface.BOLD)
            setPadding(0, dp(12), 0, dp(4))
        })

        offer.subjects.forEach { s ->
            body.addView(TextView(this).apply {
                val code = s.courseCode?.takeIf { it.isNotBlank() }?.let { "[$it] " } ?: ""
                val mark = if (s.registered) "✓ " else "• "
                text = mark + code + (s.courseName ?: "")
                setTextColor(color(R.color.text_primary))
                textSize = 13f
                setPadding(0, dp(4), 0, 0)
            })
        }
    }

    /** Open offer: checkbox per course; Register unlocks only when ALL are ticked. */
    private fun addRegistrationForm(body: LinearLayout, offer: CourseOffer) {
        val checks = mutableListOf<CheckBox>()

        val selectAll = CheckBox(this).apply {
            text = getString(R.string.course_reg_select_all)
            setTextColor(color(R.color.text_primary))
            setTypeface(typeface, Typeface.BOLD)
        }
        body.addView(selectAll)

        offer.subjects.forEach { s ->
            val cb = CheckBox(this).apply {
                val code = s.courseCode?.takeIf { it.isNotBlank() }?.let { "[$it] " } ?: ""
                val credit = s.credit?.takeIf { it.isNotBlank() }?.let { " · $it Cr" } ?: ""
                text = code + (s.courseName ?: "") + credit
                setTextColor(color(R.color.text_primary))
            }
            body.addView(cb)
            checks.add(cb)
        }

        body.addView(TextView(this).apply {
            text = getString(R.string.course_reg_must_select_all)
            setTextColor(color(R.color.text_secondary))
            textSize = 12f
            setPadding(0, dp(8), 0, 0)
        })

        val btn = MaterialButton(this).apply {
            text = getString(R.string.course_reg_register, offer.subjects.size)
            isEnabled = false
            layoutParams = LinearLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT,
            ).apply { topMargin = dp(12) }
            setOnClickListener { confirmAndRegister(offer) }
        }
        body.addView(btn)

        var syncing = false
        fun refresh() {
            val all = checks.isNotEmpty() && checks.all { it.isChecked }
            btn.isEnabled = all && !submitting
            syncing = true
            selectAll.isChecked = all
            syncing = false
        }
        checks.forEach { cb -> cb.setOnCheckedChangeListener { _, _ -> refresh() } }
        selectAll.setOnCheckedChangeListener { _, checked ->
            if (syncing) return@setOnCheckedChangeListener
            checks.forEach { it.isChecked = checked }
        }
    }

    private fun confirmAndRegister(offer: CourseOffer) {
        AlertDialog.Builder(this)
            .setTitle(R.string.course_reg_confirm_title)
            .setMessage(getString(R.string.course_reg_confirm_body, offer.subjects.size))
            .setNegativeButton(R.string.cancel, null)
            .setPositiveButton(R.string.course_reg_register_action) { _, _ -> register(offer.id) }
            .show()
    }

    private fun register(offerId: Int) {
        if (submitting) return
        submitting = true
        binding.progress.visibility = View.VISIBLE

        lifecycleScope.launch {
            when (val result = app.repository.registerAllCourses(offerId)) {
                is AppResult.Success -> Toast.makeText(
                    this@CourseRegistrationActivity,
                    result.data.message ?: getString(R.string.course_reg_submitted),
                    Toast.LENGTH_LONG,
                ).show()
                is AppResult.Error -> Toast.makeText(
                    this@CourseRegistrationActivity, result.message, Toast.LENGTH_LONG,
                ).show()
            }
            submitting = false
            load()
        }
    }

    private fun dp(v: Int): Int = (v * resources.displayMetrics.density).toInt()
    private fun color(res: Int) = ContextCompat.getColor(this, res)

    companion object {
        private const val STATUS_PENDING = "pending"
    }
}

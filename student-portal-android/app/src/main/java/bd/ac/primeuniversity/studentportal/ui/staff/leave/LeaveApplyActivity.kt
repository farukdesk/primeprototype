package bd.ac.primeuniversity.studentportal.ui.staff.leave

import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.os.Bundle
import android.view.View
import android.widget.AdapterView
import android.widget.ArrayAdapter
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.databinding.ActivityLeaveApplyBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch
import java.util.Calendar
import java.util.Locale

/**
 * New leave request form. Mirrors the admin-panel rules: Casual & Sick consume
 * the yearly balance, Additional is Paid/Unpaid, Short leave is a single day
 * with a time range; Duty/Maternity/Paternity are paid, Study/Extra Ordinary
 * are unpaid.
 */
class LeaveApplyActivity : AppCompatActivity() {

    private lateinit var binding: ActivityLeaveApplyBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    private val categories = listOf(
        "casual" to "Casual Leave",
        "sick" to "Sick Leave",
        "additional" to "Additional Leave",
        "short" to "Short Leave",
        "duty" to "Duty Leave",
        "extraordinary" to "Extra Ordinary Leave",
        "maternity" to "Maternity Leave",
        "paternity" to "Paternity Leave",
        "study" to "Study Leave",
    )

    private val selectedCategory: String
        get() = categories[binding.spCategory.selectedItemPosition].first

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLeaveApplyBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        binding.spCategory.adapter = ArrayAdapter(
            this, android.R.layout.simple_spinner_dropdown_item, categories.map { it.second }
        )
        binding.spPayType.adapter = ArrayAdapter(
            this, android.R.layout.simple_spinner_dropdown_item, listOf("Paid", "Unpaid")
        )

        binding.spCategory.onItemSelectedListener = object : AdapterView.OnItemSelectedListener {
            override fun onItemSelected(p: AdapterView<*>?, v: View?, pos: Int, id: Long) {
                onCategoryChanged()
            }

            override fun onNothingSelected(p: AdapterView<*>?) = Unit
        }

        binding.startDate.setOnClickListener { pickDate(binding.startDate) }
        binding.endDate.setOnClickListener { pickDate(binding.endDate) }
        binding.startTime.setOnClickListener { pickTime(binding.startTime) }
        binding.endTime.setOnClickListener { pickTime(binding.endTime) }

        binding.btnSubmit.setOnClickListener { submit() }

        onCategoryChanged()
    }

    private fun onCategoryChanged() {
        val cat = selectedCategory
        binding.payTypeWrap.visibility = if (cat == "additional") View.VISIBLE else View.GONE
        binding.timeWrap.visibility = if (cat == "short") View.VISIBLE else View.GONE
        binding.endWrap.visibility = if (cat == "short") View.GONE else View.VISIBLE
    }

    private fun pickDate(target: TextView) {
        val cal = Calendar.getInstance()
        DatePickerDialog(
            this,
            { _, y, m, d -> target.text = String.format(Locale.US, "%04d-%02d-%02d", y, m + 1, d) },
            cal.get(Calendar.YEAR), cal.get(Calendar.MONTH), cal.get(Calendar.DAY_OF_MONTH)
        ).show()
    }

    private fun pickTime(target: TextView) {
        TimePickerDialog(
            this,
            { _, h, min -> target.text = String.format(Locale.US, "%02d:%02d", h, min) },
            9, 0, false
        ).show()
    }

    private fun submit() {
        val cat = selectedCategory
        val start = binding.startDate.text.toString().trim()
        val end = if (cat == "short") start else binding.endDate.text.toString().trim()
        val reason = binding.reason.text.toString().trim()
        val startTime = binding.startTime.text.toString().trim()
        val endTime = binding.endTime.text.toString().trim()

        if (start.isEmpty()) {
            toast(getString(R.string.leave_err_start)); return
        }
        if (cat != "short" && end.isEmpty()) {
            toast(getString(R.string.leave_err_end)); return
        }
        if (cat == "short" && (startTime.isEmpty() || endTime.isEmpty())) {
            toast(getString(R.string.leave_err_times)); return
        }
        if (reason.isEmpty()) {
            toast(getString(R.string.leave_err_reason)); return
        }

        val payType = if (cat == "additional") {
            if (binding.spPayType.selectedItemPosition == 1) "unpaid" else "paid"
        } else null

        binding.btnSubmit.isEnabled = false
        binding.progress.visibility = View.VISIBLE

        lifecycleScope.launch {
            val result = app.repository.applyStaffLeave(
                category = cat,
                startDate = start,
                endDate = end,
                reason = reason,
                payType = payType,
                startTime = if (cat == "short") startTime else null,
                endTime = if (cat == "short") endTime else null,
            )
            binding.progress.visibility = View.GONE
            when (result) {
                is AppResult.Success -> {
                    toast(result.data.message ?: getString(R.string.leave_submitted))
                    finish()
                }
                is AppResult.Error -> {
                    toast(result.message)
                    binding.btnSubmit.isEnabled = true
                }
            }
        }
    }

    private fun toast(message: String) {
        Toast.makeText(this, message, Toast.LENGTH_LONG).show()
    }
}

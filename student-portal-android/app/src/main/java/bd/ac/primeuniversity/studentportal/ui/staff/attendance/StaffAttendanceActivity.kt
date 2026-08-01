package bd.ac.primeuniversity.studentportal.ui.staff.attendance

import android.content.res.ColorStateList
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.AttendanceDay
import bd.ac.primeuniversity.studentportal.data.model.AttendanceSummary
import bd.ac.primeuniversity.studentportal.databinding.ActivityStaffAttendanceBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemAttendanceDayBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale

/**
 * My Attendance: day-wise statement over the Prime payroll month
 * (26th of the previous month to the 25th) with a summary and month paging.
 */
class StaffAttendanceActivity : AppCompatActivity() {

    private lateinit var binding: ActivityStaffAttendanceBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    private val cal: Calendar = Calendar.getInstance()
    private val adapter = DayAdapter()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityStaffAttendanceBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }
        binding.list.layoutManager = LinearLayoutManager(this)
        binding.list.adapter = adapter

        binding.btnPrev.setOnClickListener { cal.add(Calendar.MONTH, -1); load() }
        binding.btnNext.setOnClickListener { cal.add(Calendar.MONTH, 1); load() }

        load()
    }

    private val monthParam: String
        get() = String.format(
            Locale.US, "%04d-%02d", cal.get(Calendar.YEAR), cal.get(Calendar.MONTH) + 1
        )

    private fun load() {
        binding.monthLabel.text = SimpleDateFormat("MMMM yyyy", Locale.US).format(cal.time)
        binding.rangeLabel.text = ""
        binding.summary.text = ""
        binding.progress.visibility = View.VISIBLE
        binding.emptyState.visibility = View.GONE
        adapter.submit(emptyList())

        lifecycleScope.launch {
            when (val result = app.repository.getStaffAttendance(monthParam)) {
                is AppResult.Success -> {
                    val data = result.data
                    binding.rangeLabel.text = data.label.orEmpty()
                    binding.summary.text = summaryText(data.summary)
                    // Latest first: today, then yesterday, then the day before.
                    // Upcoming days go to the bottom in ascending order.
                    val today = SimpleDateFormat("yyyy-MM-dd", Locale.US)
                        .format(Calendar.getInstance().time)
                    val (pastOrToday, upcoming) = data.days.partition { it.date <= today }
                    adapter.submit(
                        pastOrToday.sortedByDescending { it.date } + upcoming.sortedBy { it.date }
                    )
                    if (data.days.isEmpty()) {
                        binding.emptyState.setText(R.string.attendance_empty)
                        binding.emptyState.visibility = View.VISIBLE
                    }
                }
                is AppResult.Error -> {
                    binding.emptyState.text = result.message
                    binding.emptyState.visibility = View.VISIBLE
                }
            }
            binding.progress.visibility = View.GONE
        }
    }

    private fun summaryText(s: AttendanceSummary?): String {
        if (s == null) return ""
        val sep = " \u00b7 "
        return "Present ${s.present}" + sep +
            "Late In ${s.lateIn}" + sep +
            "Early Out ${s.earlyOut}" + sep +
            "Absent ${s.absent}" + sep +
            "Leave ${s.leave}" + sep +
            "Penalty ${s.latePenaltyDays}"
    }

    private class DayAdapter : RecyclerView.Adapter<DayAdapter.VH>() {

        private val items = mutableListOf<AttendanceDay>()

        fun submit(list: List<AttendanceDay>) {
            items.clear()
            items.addAll(list)
            notifyDataSetChanged()
        }

        class VH(val b: ItemAttendanceDayBinding) : RecyclerView.ViewHolder(b.root)

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH =
            VH(ItemAttendanceDayBinding.inflate(LayoutInflater.from(parent.context), parent, false))

        override fun getItemCount(): Int = items.size

        override fun onBindViewHolder(holder: VH, position: Int) {
            val d = items[position]
            val ctx = holder.b.root.context

            holder.b.day.text = d.date.takeLast(2)
            holder.b.weekday.text = d.weekday
            holder.b.times.text =
                "In ${d.inTime ?: "\u2014"} \u00b7 Out ${d.outTime ?: "\u2014"}"

            val sub = d.holiday ?: d.worked
            holder.b.worked.text = sub.orEmpty()
            holder.b.worked.visibility = if (sub.isNullOrBlank()) View.GONE else View.VISIBLE

            holder.b.status.text = d.statusLabel
            holder.b.status.backgroundTintList = ColorStateList.valueOf(
                ContextCompat.getColor(ctx, statusColor(d.status))
            )
        }

        private fun statusColor(status: String): Int = when (status) {
            "present" -> R.color.success
            "absent" -> R.color.error
            "late_in", "early_out", "late_and_early",
            "incomplete", "short_hours" -> R.color.warning
            "leave" -> R.color.info
            "holiday", "weekly_off" -> R.color.purple
            else -> R.color.slate // upcoming
        }
    }
}

package bd.ac.primeuniversity.studentportal.ui.staff.invigilation

import android.content.res.ColorStateList
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.InvigilationDuty
import bd.ac.primeuniversity.studentportal.databinding.ActivityInvigilationDutyBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemInvigilationDutyBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemInvigilationSectionBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/**
 * Faculty: the signed-in employee's invigilation duties, split into Upcoming
 * and Past sections with summary cards (Total / Upcoming / Attended). Mirrors
 * the web self-service page admin/invigilation-duty/index.php. Read-only.
 */
class InvigilationDutyActivity : AppCompatActivity() {

    private lateinit var binding: ActivityInvigilationDutyBinding
    private val app: PrimeApp by lazy { application as PrimeApp }
    private val adapter = DutyAdapter()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityInvigilationDutyBinding.inflate(layoutInflater)
        setContentView(binding.root)

        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { _, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            binding.root.updatePadding(top = bars.top, bottom = bars.bottom)
            insets
        }

        binding.btnBack.setOnClickListener { finish() }
        binding.list.layoutManager = LinearLayoutManager(this)
        binding.list.adapter = adapter
    }

    override fun onResume() {
        super.onResume()
        load()
    }

    private fun load() {
        binding.progress.visibility = View.VISIBLE
        binding.emptyState.visibility = View.GONE
        binding.summaryRow.visibility = View.GONE

        lifecycleScope.launch {
            when (val result = app.repository.getInvigilationDuties()) {
                is AppResult.Success -> render(result.data)
                is AppResult.Error -> {
                    adapter.submit(emptyList())
                    binding.emptyState.text = result.message
                    binding.emptyState.visibility = View.VISIBLE
                }
            }
            binding.progress.visibility = View.GONE
        }
    }

    private fun render(data: bd.ac.primeuniversity.studentportal.data.model.InvigilationDutyResponse) {
        if (!data.hasProfile) {
            adapter.submit(emptyList())
            binding.emptyState.setText(R.string.invigilation_no_profile)
            binding.emptyState.visibility = View.VISIBLE
            return
        }

        val s = data.summary
        binding.summaryRow.visibility = View.VISIBLE
        binding.totalValue.text = (s?.total ?: 0).toString()
        binding.upcomingValue.text = (s?.upcoming ?: 0).toString()
        binding.attendedValue.text = (s?.attended ?: 0).toString()

        val rows = buildList {
            add(Row.Section(getString(R.string.invigilation_section_upcoming)))
            if (data.upcoming.isEmpty()) {
                add(Row.Empty(getString(R.string.invigilation_none_upcoming)))
            } else {
                data.upcoming.forEach { add(Row.Duty(it)) }
            }
            add(Row.Section(getString(R.string.invigilation_section_past)))
            if (data.past.isEmpty()) {
                add(Row.Empty(getString(R.string.invigilation_none_past)))
            } else {
                data.past.forEach { add(Row.Duty(it)) }
            }
        }
        adapter.submit(rows)
    }

    private sealed interface Row {
        data class Section(val title: String) : Row
        data class Duty(val duty: InvigilationDuty) : Row
        data class Empty(val text: String) : Row
    }

    private class DutyAdapter : RecyclerView.Adapter<RecyclerView.ViewHolder>() {

        private val items = mutableListOf<Row>()

        fun submit(list: List<Row>) {
            items.clear()
            items.addAll(list)
            notifyDataSetChanged()
        }

        override fun getItemCount(): Int = items.size

        override fun getItemViewType(position: Int): Int = when (items[position]) {
            is Row.Section -> TYPE_SECTION
            is Row.Duty -> TYPE_DUTY
            is Row.Empty -> TYPE_EMPTY
        }

        class SectionVH(val b: ItemInvigilationSectionBinding) : RecyclerView.ViewHolder(b.root)
        class DutyVH(val b: ItemInvigilationDutyBinding) : RecyclerView.ViewHolder(b.root)
        class EmptyVH(val b: ItemInvigilationSectionBinding) : RecyclerView.ViewHolder(b.root)

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder {
            val inflater = LayoutInflater.from(parent.context)
            return when (viewType) {
                TYPE_DUTY -> DutyVH(ItemInvigilationDutyBinding.inflate(inflater, parent, false))
                TYPE_EMPTY -> EmptyVH(ItemInvigilationSectionBinding.inflate(inflater, parent, false))
                else -> SectionVH(ItemInvigilationSectionBinding.inflate(inflater, parent, false))
            }
        }

        override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
            when (val row = items[position]) {
                is Row.Section -> (holder as SectionVH).b.sectionTitle.text = row.title
                is Row.Empty -> (holder as EmptyVH).b.sectionTitle.apply {
                    text = row.text
                    setTextColor(
                        ContextCompat.getColor(context, android.R.color.darker_gray)
                    )
                }
                is Row.Duty -> bindDuty((holder as DutyVH).b, row.duty)
            }
        }

        private fun bindDuty(b: ItemInvigilationDutyBinding, d: InvigilationDuty) {
            val ctx = b.root.context
            b.dateLabel.text = d.dateLabel
            b.weekday.text = d.weekday
            b.exam.text = buildString {
                append(d.examName)
                if (!d.examYear.isNullOrBlank()) append(" (").append(d.examYear).append(")")
            }
            b.time.text = d.timeSlot
            b.room.text = ctx.getString(R.string.invigilation_room_fmt, d.roomNumber)

            b.role.text = ctx.getString(R.string.invigilation_role_fmt, d.myPosition)
            b.role.backgroundTintList = ColorStateList.valueOf(
                ContextCompat.getColor(ctx, if (d.myPosition == 1) R.color.primary else R.color.info)
            )

            b.coInvigilator.text = ctx.getString(
                R.string.invigilation_co_fmt,
                d.coName?.takeIf { it.isNotBlank() } ?: ctx.getString(R.string.dash),
            )

            val (labelRes, colorRes) = when (d.attended) {
                1 -> R.string.invigilation_attended_badge to R.color.success
                0 -> R.string.invigilation_absent_badge to R.color.error
                else -> R.string.invigilation_pending_badge to R.color.info
            }
            b.attendance.setText(labelRes)
            b.attendance.backgroundTintList =
                ColorStateList.valueOf(ContextCompat.getColor(ctx, colorRes))
        }

        companion object {
            private const val TYPE_SECTION = 0
            private const val TYPE_DUTY = 1
            private const val TYPE_EMPTY = 2
        }
    }
}
